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
