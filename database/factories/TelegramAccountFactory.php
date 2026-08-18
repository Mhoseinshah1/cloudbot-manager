<?php

namespace Database\Factories;

use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class TelegramAccountFactory extends Factory
{
    protected $model = TelegramAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'telegram_chat_id' => fake()->unique()->randomNumber(8),
            'telegram_user_id' => fake()->unique()->randomNumber(8),
            'first_name' => fake()->firstName(),
            'username' => fake()->userName(),
            'is_verified' => false,
        ];
    }
}
