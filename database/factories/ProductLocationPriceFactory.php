<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductLocationPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductLocationPrice>
 */
class ProductLocationPriceFactory extends Factory
{
    protected $model = ProductLocationPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'active' => true,
            // A plausible Toman price, as an int.
            'selling_price_toman' => 1_500_000,
            // A decimal string, never a float literal.
            'provider_cost_snapshot' => '4.550000',
            'provider_currency' => 'EUR',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    public function withoutProviderCost(): static
    {
        return $this->state(fn (): array => ['provider_cost_snapshot' => null]);
    }
}
