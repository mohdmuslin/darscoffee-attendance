<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * HTTPS behind a proxy.
 *
 * The failure this guards against is specific and expensive to diagnose: on a host that
 * terminates TLS or sits behind a CDN, PHP sees HTTPS requests as plain HTTP unless the
 * proxy's forwarded headers are trusted. Signed photograph URLs are then generated over
 * `http://`, redirect to `https://`, and fail signature validation — because
 * `hasValidSignature()` validates against the REQUEST's scheme, not the generated one.
 *
 * The symptom is a console that loads, an API that works, and photographs that return 403.
 * The obvious suspects are the signed URL TTL and the file permissions, and neither is the
 * cause.
 *
 * HOW THE SETTING IS APPLIED
 *
 * The real switch is `TRUSTED_PROXIES` in the environment, read by `bootstrap/app.php` when
 * the kernel is resolved. `config()` is NOT available at that point — using it there throws
 * "Target class [config] does not exist" and takes the whole application down, artisan
 * included, which is exactly what happened while writing this.
 *
 * So these tests drive the framework middleware directly rather than pretending the config
 * repository is the mechanism.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => false,
    ]);

    $this->owner = User::factory()->create(['role' => UserRole::OWNER]);
});

/**
 * Run the framework's TrustProxies middleware with a given trust setting.
 *
 * Uses the framework's own `at()` static rather than assigning `$proxies`, which is protected
 * — reaching into it is what the framework expects you to use this for. `flushState()` on the
 * way out matters because the static persists for the whole PHP process and Pest loads every
 * file into one: without it, one test trusting `*` would leave every later test trusting the
 * forwarded headers, and the "safe default" test would pass or fail depending on file order.
 */
function trustProxiesFor(Request $request, string|array|null $proxies): Request
{
    if ($proxies !== null) {
        TrustProxies::at($proxies);
    }

    try {
        (new TrustProxies)->handle($request, fn ($r) => $r);
    } finally {
        TrustProxies::flushState();
    }

    return $request;
}

/**
 * THE SAFE DEFAULT: with nothing trusted, forwarded headers are ignored.
 *
 * Not a limitation but the point. The punch endpoint is public, rate-limited by IP, and
 * records that IP on every attempt as evidence — so a direct caller must not be able to
 * choose their own scheme or forge their own address.
 */
it('ignores forwarded headers when no proxy is trusted', function () {
    $request = Request::create('/api/v1/auth/me', 'GET');
    $request->headers->set('X-Forwarded-Proto', 'https');
    $request->headers->set('X-Forwarded-For', '203.0.113.9');

    trustProxiesFor($request, null);

    expect($request->secure())->toBeFalse()
        // The forged address is ignored too, so a rate limiter cannot be evaded by header.
        ->and($request->ip())->not->toBe('203.0.113.9');
});

/**
 * THE FIX: with a proxy configured, the forwarded proto is honoured.
 *
 * This is the single assertion that decides whether every staff photograph loads on the live
 * site. Without it, `URL::temporarySignedRoute()` builds an `http://` URL, the browser
 * redirects to `https://`, and `hasValidSignature()` compares against the request's scheme
 * and refuses.
 */
it('honours the forwarded proto when a proxy is trusted', function () {
    $request = Request::create('/api/v1/auth/me', 'GET');
    $request->headers->set('X-Forwarded-Proto', 'https');

    trustProxiesFor($request, '*');

    expect($request->secure())->toBeTrue();
});

/**
 * A trusted proxy is also where the client's real IP comes from.
 *
 * Behind a proxy every request arrives from the proxy itself, so without this the punch trail
 * records one address for every employee at every outlet — the evidence column becomes
 * useless, and the rate limiter throttles the whole shop as though it were one attacker.
 */
it('resolves the client ip from the forwarded header when a proxy is trusted', function () {
    $request = Request::create('/api/v1/punch/start', 'POST');
    $request->headers->set('X-Forwarded-For', '203.0.113.9');

    trustProxiesFor($request, '*');

    expect($request->ip())->toBe('203.0.113.9');
});

/**
 * The live-site failure and its fix, on the real signed route.
 *
 * The request is built from a PLAIN HTTP url carrying `X-Forwarded-Proto: https` — which is
 * what actually arrives from a TLS-terminating proxy. Building it from an `https://` URL
 * instead would set the scheme directly, TrustProxies would never be involved, and the test
 * would pass without proving anything. That mistake was made once while writing this.
 *
 *    proxy trusted      -> secure, signature valid, photographs load
 *    proxy NOT trusted  -> insecure, signature INVALID, every photograph 403s
 *
 * Both halves matter. The second is the bug; the first is the fix.
 */
it('validates a signed photo url only when the proxy is trusted', function () {
    $employee = Employee::create([
        'employee_code' => 'RAM-001',
        'name' => 'Ali bin Ahmad',
        'is_active' => true,
    ]);
    $employee->outlets()->attach($this->outlet->id);

    Storage::disk('local')->put('employees/ali.jpg', 'bytes');
    $employee->update(['photo_path' => 'employees/ali.jpg']);

    // Signed for the https host, as the live site generates it.
    URL::forceScheme('https');

    $signed = URL::temporarySignedRoute(
        'photos.show',
        now()->addMinutes(30),
        ['path' => 'employees/ali.jpg'],
    );

    URL::forceScheme(null);

    expect($signed)->toStartWith('https://');

    // The proxy forwards a PLAIN HTTP request, having terminated TLS itself.
    $forwarded = str_replace('https://', 'http://', $signed);

    $trusted = Request::create($forwarded, 'GET');
    $trusted->headers->set('X-Forwarded-Proto', 'https');
    trustProxiesFor($trusted, '*');

    expect($trusted->secure())->toBeTrue()
        ->and(URL::hasValidSignature($trusted))->toBeTrue();

    $untrusted = Request::create($forwarded, 'GET');
    $untrusted->headers->set('X-Forwarded-Proto', 'https');
    trustProxiesFor($untrusted, null);

    // This is the production failure: the console loads, the API works, and the photographs
    // are all refused — with the TTL and the file permissions as the obvious red herrings.
    expect($untrusted->secure())->toBeFalse()
        ->and(URL::hasValidSignature($untrusted))->toBeFalse();
});
