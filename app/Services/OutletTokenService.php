<?php

namespace App\Services;

use App\Enums\OutletTokenMode;
use App\Models\Outlet;
use App\Models\OutletToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Issues and revokes punch codes.
 *
 * Both modes share one rule: GENERATING A NEW CODE REVOKES THE PREVIOUS ONE for
 * that outlet, inside one transaction. That is what makes "the latest code is the
 * applicable one" true, and it is the recovery path when a printed sheet leaks —
 * reprinting kills the old sheet immediately.
 *
 * Once a code is revoked, punches carrying it are refused, so the revocation takes
 * effect on the next scan rather than after a cache expires.
 */
class OutletTokenService
{
    /**
     * Issue a fresh code for the outlet, superseding any live one.
     *
     * @param  User|null  $actor  Null when a display device generated it.
     */
    public function regenerate(Outlet $outlet, ?User $actor = null): OutletToken
    {
        return DB::transaction(function () use ($outlet, $actor) {
            $this->revokeLive($outlet, $actor);

            $token = OutletToken::generateFor($outlet, $actor);

            /*
             * Count the roll so the console can show how long a printed sheet has
             * been in use — a code that has never been reprinted since it was
             * issued months ago is worth a second look.
             */
            $token->forceFill(['generations' => ($outlet->tokens()->max('generations') ?? 0) + 1])->save();

            return $token;
        });
    }

    /**
     * Revoke every live code for an outlet.
     *
     * Used before issuing a replacement, and available on its own so a code can be
     * killed without immediately issuing another — an outlet can be left with no
     * valid code, which is the safe direction to fail.
     */
    public function revokeLive(Outlet $outlet, ?User $actor = null): int
    {
        $live = $outlet->tokens()->live()->get();

        foreach ($live as $token) {
            $token->revoke($actor);
        }

        return $live->count();
    }

    /**
     * The code currently in force for an outlet, or null if none.
     *
     * For a rotating outlet this is refreshed on demand by the display device; for
     * a printed outlet it is whatever was last issued, which is what the print
     * sheet shows.
     */
    public function currentFor(Outlet $outlet): ?OutletToken
    {
        return $outlet->tokens()
            ->live()
            ->latest('id')
            ->first();
    }

    /**
     * The code a display device should show right now.
     *
     * A rotating code is issued lazily: the device asks, and if the current code is
     * missing or close to expiring a new one is issued. That keeps the code fresh
     * without a cron job, which matters on shared hosting where a scheduled refresh
     * every 90 seconds would be absurd.
     *
     * Printed outlets never reach here — they have no device.
     */
    public function currentOrFreshForDisplay(Outlet $outlet): OutletToken
    {
        if ($outlet->token_mode !== OutletTokenMode::ROTATING) {
            // A printed outlet has no display; return whatever is in force.
            return $this->currentFor($outlet) ?? $this->regenerate($outlet);
        }

        $current = $this->currentFor($outlet);

        /*
         * Refresh with headroom. Renewing only on expiry would hand a phone a code
         * that dies mid-scan, so renew once a third of the window remains.
         */
        if ($current !== null && $current->expires_at->isAfter(now()->addSeconds(
            (int) ($outlet->qr_ttl_seconds / 3)
        ))) {
            return $current;
        }

        return $this->regenerate($outlet);
    }

    /**
     * Find a usable code.
     *
     * Two distinct paths, and getting them the same way round would be a security
     * bug:
     *
     *  NOW (a live scan, no timestamp) is checked STRICTLY with `isLive()`. The
     *  server's clock is authoritative, so a revoked code must be refused the
     *  instant it is revoked — that is the entire point of reprinting after a leak.
     *
     *  A GIVEN MOMENT (an offline punch, timestamp supplied) is checked with
     *  `wasLiveAt()`, which allows a minute of clock skew because the timestamp
     *  comes from the employee's phone.
     *
     * Applying the skew tolerance to a live scan would leave a leaked code usable
     * for another minute after the manager reprinted it — small, but exactly the
     * window an attacker is waiting for.
     */
    public function findValid(string $token, ?\DateTimeInterface $at = null): ?OutletToken
    {
        $record = OutletToken::query()
            ->with('outlet')
            ->where('token', $token)
            ->first();

        if ($record === null || $record->outlet === null || ! $record->outlet->is_active) {
            return null;
        }

        $valid = $at === null
            ? $record->isLive()
            : $record->wasLiveAt($at);

        return $valid ? $record : null;
    }
}
