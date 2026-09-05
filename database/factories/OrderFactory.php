<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Orders\OrderService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => OrderService::newOrderNumber(),
            'total_toman' => 1_500_000,
            'idempotency_key' => (string) Str::uuid(),
            'cost_snapshot' => ['provider_cost' => '4.550000', 'provider_currency' => 'EUR'],
            'pricing_snapshot' => ['selling_price_toman' => 1_500_000],
            'aup_version' => '2026-01',
            'aup_accepted_at' => now(),
        ];
    }
}
