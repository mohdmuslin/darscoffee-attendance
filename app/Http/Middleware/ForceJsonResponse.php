<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force JSON responses on API routes.
 *
 * Without this, an unauthenticated request that does not send `Accept:
 * application/json` is redirected to a named `login` route, which an API-only app
 * does not have — producing an HTTP 500 from a missing route instead of a clean
 * 401. That failure is confusing enough that it cost real time on the ordering
 * project, so the fix is carried over rather than rediscovered.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
