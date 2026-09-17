<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base controller.
 *
 * `AuthorizesRequests` is included because Laravel 13's slim application does not
 * place it here by default, so `$this->authorize(...)` fails with "Call to
 * undefined method" — which surfaces as an opaque HTTP 500 rather than a permission
 * error, and looks like a broken endpoint rather than a missing trait.
 *
 * Adding it once here means every controller gets policy support, instead of each
 * one having to remember the trait.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
