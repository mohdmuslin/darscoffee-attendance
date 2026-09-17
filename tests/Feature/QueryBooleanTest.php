<?php

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\User;

/**
 * Query-string booleans.
 *
 * A query string can only carry text, and Laravel's `boolean` validation rule accepts 1,
 * 0, "1", "0", true and false — but not the strings "true" and "false". A JavaScript client
 * sending the natural `{ flag: true }` therefore produced a 422 on a filter the user had
 * merely ticked a box for.
 */
beforeEach(function () {
    $this->owner = User::factory()->create(['role' => UserRole::OWNER]);

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'token_mode' => 'printed',
        'requires_photo' => false,
    ]);
});

it('accepts the string "true" for a filter flag', function () {
    $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/anomalies?unreviewed_only=true')
        ->assertOk();
});

it('accepts the string "false" for a filter flag', function () {
    // 0 rather than being dropped: the value WAS supplied, and treating "false" as "not
    // specified" would silently apply the default instead of the requested behaviour.
    $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/anomalies?unreviewed_only=false')
        ->assertOk();
});

it('accepts the numeric forms as well', function () {
    $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/anomalies?unreviewed_only=1')
        ->assertOk();

    $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/anomalies?unreviewed_only=0')
        ->assertOk();
});

it('still rejects a value that is not a boolean at all', function () {
    /*
     * Only "true" and "false" are converted. Anything else must fail loudly rather than
     * being coerced to false, or a typo in a filter becomes a silent behaviour change.
     */
    $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/anomalies?unreviewed_only=maybe')
        ->assertStatus(422)
        ->assertJsonValidationErrors('unreviewed_only');
});

it('accepts a string boolean on the employee filter', function () {
    // The pre-existing endpoint, which the console already worked around by sending 1.
    $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/employees?include_inactive=true')
        ->assertOk();
});

it('accepts a string boolean on the punch trail filter', function () {
    $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/punch-events?failures_only=true')
        ->assertOk();
});
