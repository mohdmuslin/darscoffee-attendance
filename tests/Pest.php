<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
| Without this file Pest has no base TestCase, so the Laravel application is
| never bootstrapped and tests fail with "Target class [config] does not exist".
| That message points at the symptom rather than the cause, so it is worth
| remembering: a missing Pest.php looks like a broken container.
*/

uses(
    TestCase::class,
    RefreshDatabase::class,
)->in('Feature');

/*
 * Unit tests get the application but NOT the database: anything needing a schema
 * belongs in Feature, where RefreshDatabase keeps tests isolated.
 */
uses(TestCase::class)->in('Unit');
