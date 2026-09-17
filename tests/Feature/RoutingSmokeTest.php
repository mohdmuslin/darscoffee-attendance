<?php

/*
 * Routing and shell smoke tests.
 *
 * These cover the traps that cost real time on the ordering system: an API-only
 * app returning a 500 instead of a 401, and one SPA catch-all swallowing another.
 * Both are silent until something calls them, which is exactly why they are
 * asserted rather than eyeballed.
 */

it('serves the punch shell at the root and at /punch', function () {
    // Root redirects so a staff bookmark of the bare domain still works.
    $this->get('/')->assertRedirect('/punch');

    $this->get('/punch')
        ->assertOk()
        ->assertSee('id="punch-app"', false)
        // The manifest link matters: without it the install prompt never appears.
        ->assertSee('manifest.webmanifest', false);
});

it('serves the console shell for any console path', function () {
    // History-mode routing means deep links must return the shell.
    $this->get('/console')
        ->assertOk()
        ->assertSee('id="console-app"', false);

    $this->get('/console/dashboard')->assertOk()->assertSee('id="console-app"', false);
    $this->get('/console/employees/12/edit')->assertOk()->assertSee('id="console-app"', false);
});

it('does not let the punch catch-all swallow the console', function () {
    /*
     * Both apps use a catch-all, so declaration order decides who wins. If the
     * punch shell ever captured /console/*, the console would load — as the punch
     * app — and every manager screen would silently be the wrong application.
     */
    $this->get('/console')
        ->assertOk()
        ->assertSee('id="console-app"', false)
        ->assertDontSee('id="punch-app"', false);
});

it('does not let the punch catch-all swallow the API', function () {
    /*
     * The equivalent failure on the ordering system dropped an entire customer
     * API group from the route file, and requests to it returned HTML from the SPA
     * shell with a 200 — so clients saw "success" and parsed nothing.
     *
     * An API response must be JSON, never the SPA shell.
     */
    $response = $this->getJson('/api/v1/nothing-here');

    // 404 (route missing) is fine. HTML from the SPA shell is not.
    expect($response->headers->get('Content-Type'))->toContain('application/json');
    expect($response->getContent())->not->toContain('punch-app');
});

it('returns 401 for an unauthenticated API call rather than a 500', function () {
    /*
     * Without ForceJsonResponse, a request that does not send Accept:
     * application/json is redirected to a named `login` route. An API-only app has
     * no such route, so the framework raises "Route [login] not defined" and the
     * client sees an HTTP 500 for what is simply an expired token.
     *
     * That misdiagnosis cost hours on the ordering system, so it is pinned here
     * against a real authenticated endpoint.
     */
    $this->get('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('message', 'Unauthenticated.');
});

it('exposes a health check for uptime monitoring', function () {
    $this->get('/up')->assertOk();
});

it('does not serve a service worker for the console', function () {
    /*
     * Deliberate asymmetry: the punch app will get a service worker for the
     * offline queue, but the console must never cache. A stale timesheet would
     * show a manager hours that are no longer true.
     */
    $this->get('/console')
        ->assertOk()
        ->assertDontSee('serviceWorker', false);
});
