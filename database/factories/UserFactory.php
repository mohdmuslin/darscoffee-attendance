<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * `role` is set explicitly. The column defaults to `manager`, and a manager
     * with no outlet mapping sees nothing (the scope fails closed), so a factory
     * user without a role would be a confusing fixture that silently returns empty
     * results. Tests should state which role they mean.
     *
     * `owner` is the default here because it is the only role that needs no extra
     * setup to be useful.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::OWNER,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /** An outlet-scoped manager. Map outlets afterwards with `->forOutlet()`. */
    public function manager(): static
    {
        return $this->state(fn () => ['role' => UserRole::MANAGER]);
    }

    /**
     * A manager mapped to the given outlets.
     *
     * Without a mapping a manager sees nothing, so this is what makes a manager
     * fixture actually able to see anything.
     */
    public function forOutlets(Outlet ...$outlets): static
    {
        return $this->manager()->after(
            fn (User $user) => $user->outlets()->sync(collect($outlets)->pluck('id')->all())
        );
    }

    /** A deactivated account, for testing that deactivation takes effect at once. */
    public function deactivated(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
