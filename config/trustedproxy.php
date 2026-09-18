<?php

/*
 * Trusted proxies.
 *
 * WHY THIS FILE EXISTS AT ALL
 *
 * Laravel 13's slim skeleton ships `TrustProxies` in the global middleware stack but
 * with NOTHING trusted — `setTrustedProxies([])`. On a host that terminates TLS or
 * sits behind a CDN, PHP therefore sees the request as plain HTTP even though the
 * browser used HTTPS, and two things go wrong:
 *
 *   1. `URL::temporarySignedRoute()` generates photo URLs over `http://`.
 *   2. `hasValidSignature()` validates against `$request->getSchemeAndHttpHost()` —
 *      the REQUEST'S scheme, not the generated one. So the redirect from http to
 *      https changes the scheme, the signature no longer matches, and every staff
 *      photograph returns 403.
 *
 * That failure is specific and easy to misdiagnose: the console renders, the API
 * works, only the photographs are broken — and the obvious suspects are the signed
 * URL TTL or the storage permissions, neither of which is the cause.
 *
 * WHY `URL::forceScheme('https')` IS NOT THE FIX
 *
 * Forcing the generated scheme makes the mismatch WORSE, not better: the URL is
 * generated as https while the request is still detected as http, so validation
 * compares against `http://host` and fails immediately. The scheme has to be
 * detected correctly in the first place, which is what trusting the proxy does.
 *
 * WHY IT DEFAULTS TO NOTHING
 *
 * `*` trusts the `X-Forwarded-*` headers from ANY caller, which is fine when every
 * request genuinely arrives through the proxy and wrong when the application is
 * reachable directly — anyone could then set `X-Forwarded-Proto` themselves and
 * choose the scheme, and `X-Forwarded-For` to claim any IP. That matters here more
 * than in most applications because the punch endpoint is public, rate-limited BY
 * IP, and records the IP on every attempt as evidence.
 *
 * So: off by default, set it to `*` only on a host you know is proxied, and prefer
 * a CIDR range where the provider publishes one.
 *
 * Common values:
 *   Cloudflare, or a host whose provider hides the proxy   ->  TRUSTED_PROXIES=*
 *   A provider with published ranges                       ->  TRUSTED_PROXIES=10.0.0.0/8
 *   Plain Apache/LiteSpeed terminating TLS itself          ->  leave it unset
 *
 * Verify after setting it: open the console, and confirm a staff photo loads.
 */

return [

    /*
     * Comma-separated IPs or CIDR ranges, or `*` for any.
     *
     * NOTE: this config value is NOT what applies the setting.
     *
     * `bootstrap/app.php` reads `TRUSTED_PROXIES` from the environment directly and calls
     * `trustProxies(at: ...)`, because that closure runs when the kernel is resolved — BEFORE
     * the config repository is bound. Calling `config()` there throws "Target class [config]
     * does not exist" and takes the entire application down, artisan included. That was written
     * and caught the hard way.
     *
     * With `config:cache` in place the `.env` file is not loaded on a request either, so the
     * environment variable must be set in the real environment — cPanel's cron entry and the
     * PHP-FPM pool — not only in `.env`.
     *
     * This file exists so the value is documented and readable from anywhere that legitimately
     * has the config repository, such as a health-check command. It is a mirror, not the switch.
     */
    'proxies' => env('TRUSTED_PROXIES'),

];
