<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserCreatedVia;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password-for-tests-only',
            'status' => UserStatus::Active,
            'created_via' => UserCreatedVia::Admin,
            'locale' => 'fa',
            'timezone' => 'Asia/Tehran',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * A customer who arrived through the bot: no email, no password.
     */
    public function fromTelegram(): static
    {
        return $this->state(fn (): array => [
            'name' => null,
            'email' => null,
            'password' => null,
            'created_via' => UserCreatedVia::Telegram,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Suspended]);
    }

    public function banned(): static
    {
        return $this->state(fn (): array => ['status' => UserStatus::Banned]);
    }

    /**
     * An administrator who has already enrolled a second factor.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (): array => [
            'two_factor_secret' => 'ABCDEFGHIJKLMNOP',
            'two_factor_recovery_codes' => ['aaaaa-bbbbb', 'ccccc-ddddd'],
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
