<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Single sign-on
    |--------------------------------------------------------------------------
    | Attendance is the IDENTITY SOURCE: it issues short-lived assertions that the
    | ordering system redeems. Nothing here ever calls the ordering system, which is
    | what lets Attendance be deployed first and keep working when the ordering
    | system is down or unreachable.
    |
    | See docs/sso.md for the full design and the alternatives that were rejected.
    */

    /*
    | The shared secret used to hash issued tokens. Both apps must agree on it.
    |
    | SECRET: belongs in .env only, never committed. The ordering system holds the
    | same value; rotating it invalidates any tokens in flight, which is exactly
    | what you want if it leaks.
    */
    'signing_key' => env('SSO_SIGNING_KEY'),

    /*
    | Where the ordering system lives, used to build the redirect that carries a
    | freshly issued token.
    |
    | This is also the ALLOW-LIST for return URLs: an SSO redirect that accepts any
    | host is an open redirect, which would let an attacker bounce a valid token to
    | a server they control.
    */
    'consumers' => [
        'ordering' => env('SSO_ORDERING_URL', 'http://127.0.0.1:8000'),
    ],

    /*
    | Lifetime of an issued assertion, in seconds. Short by design — long enough to
    | survive a redirect, short enough that a leaked token is useless.
    */
    'token_ttl' => (int) env('SSO_TOKEN_TTL', 60),

];
