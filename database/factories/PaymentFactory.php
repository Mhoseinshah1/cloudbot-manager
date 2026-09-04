<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Billing\Gateways\ManualGateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->fromTelegram(),
            'gateway' => ManualGateway::CODE,
            'amount_toman' => 500_000,
            'status' => PaymentStatus::Pending,
            'idempotency_key' => 'payment-'.Str::uuid(),
        ];
    }
}
