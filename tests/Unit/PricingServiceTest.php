<?php

use App\Models\Product;
use App\Models\ProviderPlan;
use App\Services\PricingService;

function makePlan(float $price = 8.5, string $currency = 'EUR'): ProviderPlan
{
    return new ProviderPlan([
        'provider_plan_id' => 'cpx21',
        'name' => 'CX21',
        'price_monthly' => $price,
        'currency' => $currency,
    ]);
}

function makeProduct(string $strategy, mixed $value = 0): Product
{
    return new Product([
        'markup_strategy' => $strategy,
        'markup_value' => $value,
        'price_toman' => $strategy === Product::MARKUP_CUSTOM ? $value : null,
    ]);
}

it('applies percentage markup and converts provider cost to toman', function () {
    $price = app(PricingService::class)->compute(
        makePlan(8.5),
        makeProduct(Product::MARKUP_PERCENTAGE, 15),
        450000,
    );

    expect($price['provider_cost'])->toBe(8.5);
    expect($price['provider_currency'])->toBe('EUR');
    expect($price['exchange_rate'])->toBe(450000.0);
    expect($price['local_cost'])->toBe(3825000);
    expect($price['selling_price'])->toBe(4398750);
    expect($price['gross_margin'])->toBe(573750);
});

it('applies fixed toman markup', function () {
    $price = app(PricingService::class)->compute(
        makePlan(4.5),
        makeProduct(Product::MARKUP_FIXED, 200000),
        450000,
    );

    expect($price['selling_price'])->toBe(2225000);
    expect($price['gross_margin'])->toBe(200000);
});

it('uses the explicit custom price without markup', function () {
    $price = app(PricingService::class)->compute(
        makePlan(15.5),
        makeProduct(Product::MARKUP_CUSTOM, 5000000),
        450000,
    );

    expect($price['selling_price'])->toBe(5000000);
    expect($price['gross_margin'])->toBe(5000000 - 6975000);
});

it('falls back to the default exchange rate from settings', function () {
    expect(app(PricingService::class)->defaultExchangeRate())->toBe(PricingService::DEFAULT_EXCHANGE_RATE);
});
