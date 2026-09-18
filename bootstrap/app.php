<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\NormaliseQueryBooleans;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Trust the proxy's forwarded headers when the host is configured to say so.
         *
         * WITHOUT this on a proxied host, PHP sees HTTPS requests as plain HTTP and every
         * signed photo URL breaks — the console renders and the API works, so the fault looks
         * like the signed URL TTL or the file permissions rather than the scheme. See
         * `config/trustedproxy.php` for the full reasoning, including why
         * `URL::forceScheme('https')` makes it worse instead of fixing it.
         *
         * READ FROM THE ENVIRONMENT, NOT `config()`. This closure runs when the kernel is
         * resolved, which is BEFORE the config repository is bound — `config('...')` here
         * throws "Target class [config] does not exist" and takes the whole application down,
         * artisan included. That was written and caught the hard way.
         *
         * `env()` is correct here because this closure runs on every request, and with
         * `config:cache` in place the `.env` file is not loaded on a request, so the value
         * must already be in the real environment — which it is, because cPanel sets it in the
         * cron and the PHP-FPM pool rather than only in `.env`. The supported way to supply it
         * is therefore the environment, and `config/trustedproxy.php` mirrors it for the
         * parts of the app that read config.
         *
         * Left unset by default, so nothing is trusted unless the host says otherwise.
         * Trusting `*` unconditionally would let any direct caller choose their own scheme and
         * forge `X-Forwarded-For` — and the punch endpoint is public, rate-limited by IP, and
         * records that IP as evidence.
         */
        $trustedProxies = env('TRUSTED_PROXIES');

        if (filled($trustedProxies)) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        /*
         * Every API request is treated as JSON, so an unauthenticated call returns
         * a clean 401 rather than redirecting to a `login` route that does not
         * exist in an API-only app — which would surface as an HTTP 500.
         *
         * The boolean normaliser runs alongside it because a query string can only carry
         * text: axios sends `?flag=true`, and Laravel's `boolean` rule does not accept the
         * string "true". Without this, tick a filter box in the console and the server
         * answers 422 naming a field the client did in fact supply.
         */
        $middleware->api(prepend: [
            ForceJsonResponse::class,
            NormaliseQueryBooleans::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
