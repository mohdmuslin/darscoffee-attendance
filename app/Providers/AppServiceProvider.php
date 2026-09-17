<?php

namespace App\Providers;

use App\Models\User;
use App\Services\SsoTokenService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The SSO signing key is read from config, not passed at each call site, so
         * only one place needs to know where the secret lives.
         */
        $this->app->singleton(SsoTokenService::class, fn () => new SsoTokenService(
            (string) config('sso.signing_key'),
        ));
    }

    public function boot(): void
    {
        /*
         * Owners pass every authorisation check; managers are checked against their
         * outlet mapping by the policy they are being tested against.
         *
         * Defined before any policy so a gate registered later cannot accidentally
         * bypass it — the ordering system learned that ordering matters here.
         */
        Gate::before(function (User $user) {
            return $user->isOwner() ? true : null;
        });
    }
}
