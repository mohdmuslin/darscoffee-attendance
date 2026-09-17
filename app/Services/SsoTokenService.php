<?php

namespace App\Services;

use App\Models\SsoToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and redeems one-time SSO assertions.
 *
 * Attendance is the identity source; the ordering system is the consumer. This
 * service is the outbound half — nothing here ever calls the ordering system, which
 * is what keeps Attendance independent of it.
 *
 * The token is a signed assertion, not a credential:
 *   - 60 second lifetime
 *   - single use, consumed atomically
 *   - stored hashed, so a database leak yields nothing redeemable
 */
class SsoTokenService
{
    /**
     * Short by design. Long enough to survive a redirect, short enough that a
     * leaked token is worthless before it can be used.
     */
    public const TTL_SECONDS = 60;

    /** The app tokens are issued for. Becomes an audience check as this grows. */
    public const AUDIENCE_ORDERING = 'ordering';

    public function __construct(
        private readonly string $signingKey,
    ) {}

    /**
     * Issue a token for a user, returning the PLAINTEXT once.
     *
     * The plaintext is never stored, so this is the only moment it exists —
     * callers must put it in the redirect immediately.
     */
    public function issue(User $user, string $audience = self::AUDIENCE_ORDERING): string
    {
        $plain = Str::random(64);

        SsoToken::create([
            'user_id' => $user->id,
            'token_hash' => $this->hash($plain),
            'audience' => $audience,
            'claims' => $this->claimsFor($user),
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return $plain;
    }

    /**
     * Redeem a token exactly once.
     *
     * Returns null for unknown, expired, already-consumed, wrong-audience, or
     * from-a-deactivated-user tokens — the same response in every case, so a
     * probing client learns nothing about which condition it hit.
     *
     * The consumption is a CONDITIONAL UPDATE claim: `WHERE consumed_at IS NULL`.
     * Two simultaneous redemptions therefore cannot both succeed, because the
     * database decides the winner rather than a read-then-write in PHP.
     */
    public function redeem(string $plain, string $audience = self::AUDIENCE_ORDERING, ?string $ip = null): ?array
    {
        $hash = $this->hash($plain);

        return DB::transaction(function () use ($hash, $audience, $ip) {
            $claimed = SsoToken::query()
                ->where('token_hash', $hash)
                ->where('audience', $audience)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update([
                    'consumed_at' => now(),
                    'consumed_by_ip' => $ip,
                ]);

            if ($claimed === 0) {
                // Unknown, expired, already used, or wrong audience.
                return null;
            }

            $record = SsoToken::where('token_hash', $hash)->first();

            $user = User::find($record->user_id);

            /*
             * Re-checked at redemption, not just at issue. A user deactivated in the
             * seconds between the two must not get a session — otherwise
             * deactivation takes effect whenever the token happened to be issued.
             */
            if ($user === null || ! $user->is_active) {
                return null;
            }

            return [
                ...$record->claims,
                'sub' => (string) $user->id,
            ];
        });
    }

    /**
     * What the consuming app is told.
     *
     * Deliberately minimal: who the person is and what they may do. Never
     * passwords, PIN hashes, IC numbers, pay rates or photos — the ordering system
     * has no business knowing what anyone is paid.
     *
     * @return array<string, mixed>
     */
    private function claimsFor(User $user): array
    {
        return [
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
            /*
             * Outlet ids, so the consumer can scope if it later becomes
             * multi-outlet. An owner has none recorded and is unrestricted, which
             * the consumer distinguishes by role rather than by an empty list.
             */
            'outlets' => $user->visibleOutletIds() ?? [],
            'email_verified' => $user->email_verified_at !== null,
        ];
    }

    private function hash(string $plain): string
    {
        /*
         * HMAC with the shared secret rather than a plain digest, so a stolen
         * database cannot be brute-forced offline without also stealing the key.
         */
        return hash_hmac('sha256', $plain, $this->signingKey);
    }
}
