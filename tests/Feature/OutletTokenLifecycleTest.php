<?php

use App\Enums\OutletTokenMode;
use App\Models\Outlet;
use App\Models\User;
use App\Services\OutletTokenService;

/**
 * Punch-code lifecycle.
 *
 * The decided model (2026-09-16): printed codes do NOT expire, and reprinting is
 * how a manager kills a leaked sheet — ad hoc, whenever they want. So reprinting
 * IS the revoke mechanism, and it has to work properly or a leaked code stays live
 * indefinitely.
 */
beforeEach(function () {
    $this->owner = User::factory()->create();

    $this->printed = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Diberanda Sg Ramal',
        'token_mode' => OutletTokenMode::PRINTED,
    ]);

    $this->rotating = Outlet::create([
        'code' => 'DARS-COFFEE',
        'name' => 'Dars Coffee',
        'token_mode' => OutletTokenMode::ROTATING,
        'qr_ttl_seconds' => 90,
    ]);

    $this->service = app(OutletTokenService::class);
});

// ---- Printed codes ----------------------------------------------------

it('issues a printed code with no expiry', function () {
    /*
     * The decision: a printed sheet stays valid until someone reprints it. An
     * expiry here would silently strand staff at the outlet with a dead sheet.
     */
    $token = $this->service->regenerate($this->printed, $this->owner);

    expect($token->expires_at)->toBeNull();
    expect($token->isLive())->toBeTrue();
});

it('supersedes the previous code when reprinted', function () {
    // The whole "latest code is the applicable one" rule.
    $first = $this->service->regenerate($this->printed, $this->owner);
    $second = $this->service->regenerate($this->printed->fresh(), $this->owner);

    expect($first->fresh()->isLive())->toBeFalse();
    expect($second->fresh()->isLive())->toBeTrue();
});

it('refuses a punch carrying a superseded code', function () {
    // The point of reprinting: a photographed sheet stops working.
    $first = $this->service->regenerate($this->printed, $this->owner);
    $this->service->regenerate($this->printed->fresh(), $this->owner);

    expect($this->service->findValid($first->token))->toBeNull();
});

it('records who revoked a code and when', function () {
    /*
     * A reprint is a security action, so it must be attributable. If a code was
     * killed, the owner needs to be able to see that it was, and by whom.
     */
    $first = $this->service->regenerate($this->printed, $this->owner);
    $this->service->regenerate($this->printed->fresh(), $this->owner);

    $revoked = $first->fresh();

    expect($revoked->revoked_at)->not->toBeNull();
    expect($revoked->revoked_by)->toBe($this->owner->id);
});

it('can revoke without issuing a replacement', function () {
    // Leaves the outlet with no valid code, which is the safe direction to fail.
    $token = $this->service->regenerate($this->printed, $this->owner);

    $count = $this->service->revokeLive($this->printed->fresh(), $this->owner);

    expect($count)->toBe(1);
    expect($this->service->currentFor($this->printed->fresh()))->toBeNull();
});

it('counts generations so a long-lived sheet is visible', function () {
    // A code never reprinted since it was issued months ago is worth a look.
    $this->service->regenerate($this->printed, $this->owner);
    $second = $this->service->regenerate($this->printed->fresh(), $this->owner);

    expect($second->generations)->toBe(2);
});

// ---- Rotating codes ---------------------------------------------------

it('expires a rotating code', function () {
    $token = $this->service->regenerate($this->rotating, $this->owner);

    expect($token->expires_at)->not->toBeNull();
    expect($token->isLive())->toBeTrue();
    expect($token->wasLiveAt(now()->addMinutes(5)))->toBeFalse();
});

it('reuses a rotating code that still has time left', function () {
    // Issuing a new code on every poll would churn rows and could hand a phone a
    // code that dies mid-scan.
    $first = $this->service->currentOrFreshForDisplay($this->rotating);
    $second = $this->service->currentOrFreshForDisplay($this->rotating);

    expect($second->id)->toBe($first->id);
});

it('renews a rotating code as it nears expiry', function () {
    $token = $this->service->currentOrFreshForDisplay($this->rotating);

    // Wind the clock to inside the renewal window.
    $this->travel(80)->seconds();

    $renewed = $this->service->currentOrFreshForDisplay($this->rotating->fresh());

    expect($renewed->id)->not->toBe($token->id);
});

// ---- Clock skew and offline punches ----------------------------------

it('accepts an offline punch made moments before the code was issued', function () {
    /*
     * Phone clocks drift. A phone running slightly behind would otherwise have its
     * legitimate punches refused as "before this code existed" — leaving the
     * employee unable to clock in with nothing they could do about it.
     */
    $token = $this->service->regenerate($this->printed, $this->owner);

    expect($token->wasLiveAt(now()->subSeconds(30)))->toBeTrue();
});

it('refuses a punch back-dated well before the code existed', function () {
    /*
     * The attack the tolerance must not open up: obtain today's code, then claim
     * to have worked yesterday. The skew allowance is a minute, so a claim that far
     * back is refused.
     */
    $token = $this->service->regenerate($this->printed, $this->owner);

    expect($token->wasLiveAt(now()->subHours(12)))->toBeFalse();
});

it('accepts a punch made just before a reprint', function () {
    // Someone clocked in seconds before the manager reprinted; their punch is real.
    $first = $this->service->regenerate($this->printed, $this->owner);

    $this->travel(10)->seconds();

    $this->service->regenerate($this->printed->fresh(), $this->owner);

    expect($first->fresh()->wasLiveAt(now()->subSeconds(5)))->toBeTrue();
});

// ---- Outlet state -----------------------------------------------------

it('refuses a code belonging to a deactivated outlet', function () {
    // Closing a site must stop punches there without deleting its history.
    $token = $this->service->regenerate($this->printed, $this->owner);

    $this->printed->update(['is_active' => false]);

    expect($this->service->findValid($token->token))->toBeNull();
});

it('returns the code in force for an outlet', function () {
    $token = $this->service->regenerate($this->printed, $this->owner);

    expect($this->service->currentFor($this->printed->fresh())->id)->toBe($token->id);
});

it('refuses an unknown token', function () {
    expect($this->service->findValid('not-a-real-token'))->toBeNull();
});
