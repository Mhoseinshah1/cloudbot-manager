<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramAccount>
 */
class TelegramAccountFactory extends Factory
{
    protected $model = TelegramAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->fromTelegram(),
            // Above the 32-bit range on purpose: real Telegram ids are.
            'telegram_user_id' => fake()->unique()->numberBetween(5_000_000_000, 9_000_000_000),
            'telegram_chat_id' => fake()->numberBetween(5_000_000_000, 9_000_000_000),
            'username' => fake()->userName(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'last_seen_at' => now(),
        ];
    }

    public function blocked(): static
    {
        return $this->state(fn (): array => ['bot_blocked_at' => now()]);
    }
}
