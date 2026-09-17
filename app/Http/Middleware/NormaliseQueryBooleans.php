<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept the string forms of booleans in query strings.
 *
 * A query string can only carry text, so an axios call with `{ unreviewed_only: true }`
 * arrives as `?unreviewed_only=true`. Laravel's `boolean` validation rule accepts 1, 0,
 * "1", "0", true and false — but NOT the strings "true" and "false", which is precisely
 * what a JavaScript client sends. The result is a confusing 422 on a filter the user only
 * ticked a box for.
 *
 * Normalising here rather than at each call site, because the alternative is remembering
 * to write `1` in every store forever — and the first person who writes the natural
 * JavaScript gets a validation error that names a field they did supply.
 *
 * Only `true` and `false` are converted. Everything else is left alone so a genuine typo
 * still fails validation loudly rather than being silently coerced to false.
 */
class NormaliseQueryBooleans
{
    public function handle(Request $request, Closure $next): Response
    {
        $normalised = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($value)) {
                $lower = strtolower($value);

                if ($lower === 'true') {
                    $normalised[$key] = 1;
                } elseif ($lower === 'false') {
                    // 0 rather than null: the value was supplied, and dropping it would
                    // silently turn "false" into "not specified".
                    $normalised[$key] = 0;
                }
            }
        }

        if ($normalised !== []) {
            $request->query->add($normalised);
        }

        return $next($request);
    }
}
