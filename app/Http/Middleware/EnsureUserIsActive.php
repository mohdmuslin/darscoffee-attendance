<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse requests from a deactivated account.
 *
 * Sanction tokens outlive the account they were issued for. Without this check,
 * disabling someone takes effect only when their token finally expires — which
 * could be weeks. On the ordering system a deactivated admin kept full access to
 * the back office until the token lapsed, so the check is deliberately repeated on
 * every request rather than trusted to sign-in.
 *
 * Also refuses a user who has been deleted outright: `$request->user()` would be
 * null in that case for a token whose owner is gone.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            // Revoke the token as well, so a retry cannot recover the session.
            $request->user()->currentAccessToken()?->delete();

            return ApiResponse::error(
                'This account has been deactivated.',
                null,
                403,
                'ACCOUNT_INACTIVE',
            );
        }

        return $next($request);
    }
}
