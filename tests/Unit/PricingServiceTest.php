<?php

use App\Enums\BillingMode;
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

it('uses the explicit hourly price for hourly products', function () {
    $plan = new ProviderPlan([
        'provider_plan_id' => 'cpx21',
        'name' => 'CX21',
        'price_monthly' => 8.5,
        'price_hourly' => 0.012,
        'currency' => 'EUR',
    ]);

    $product = new Product([
        'billing_mode' => BillingMode::Hourly->value,
        'hourly_price_toman' => 850,
        'markup_strategy' => Product::MARKUP_CUSTOM,
    ]);

    $price = app(PricingService::class)->compute($plan, $product, 450000);

    expect($price['billing_mode'])->toBe('hourly');
    expect($price['hourly_price'])->toBe(850);
    expect($price['selling_price'])->toBe(850); // primary price for hourly mode
    expect($price['monthly_cap'])->toBeNull();
    expect($price['local_hourly_cost'])->toBe(5400); // 0.012 EUR * 450000
    expect($price['provider_hourly_cost'])->toBe(0.012);
});

it('derives the hourly selling price from markup when not explicit', function () {
    $plan = new ProviderPlan([
        'provider_plan_id' => 'cpx21',
        'name' => 'CX21',
        'price_monthly' => 8.5,
        'price_hourly' => 0.012,
        'currency' => 'EUR',
    ]);

    $product = new Product([
        'billing_mode' => BillingMode::Hourly->value,
        'markup_strategy' => Product::MARKUP_PERCENTAGE,
        'markup_value' => 15,
    ]);

    $price = app(PricingService::class)->compute($plan, $product, 450000);

    expect($price['hourly_price'])->toBe(6210); // round(5400 * 1.15)
    expect($price['hourly_gross_margin'])->toBe(6210 - 5400);
});

it('computes the monthly cap for hourly_capped products', function () {
    $plan = new ProviderPlan([
        'provider_plan_id' => 'cpx21',
        'name' => 'CX21',
        'price_monthly' => 8.5,
        'price_hourly' => 0.012,
        'currency' => 'EUR',
    ]);

    $product = new Product([
        'billing_mode' => BillingMode::HourlyCapped->value,
        'hourly_price_toman' => 850,
        'monthly_cap_toman' => 399000,
        'markup_strategy' => Product::MARKUP_CUSTOM,
    ]);

    $price = app(PricingService::class)->compute($plan, $product, 450000);

    expect($price['hourly_price'])->toBe(850);
    expect($price['monthly_cap'])->toBe(399000);
    expect($price['selling_price'])->toBe(850);
    expect($price['billing_mode'])->toBe('hourly_capped');
});

it('keeps provider cost and customer price fully separate', function () {
    $plan = new ProviderPlan([
        'provider_plan_id' => 'cpx21',
        'name' => 'CX21',
        'price_monthly' => 8.5,
        'price_hourly' => 0.012,
        'currency' => 'EUR',
    ]);

    $product = new Product([
        'billing_mode' => BillingMode::HourlyCapped->value,
        'hourly_price_toman' => 850,
        'monthly_cap_toman' => 399000,
        'markup_strategy' => Product::MARKUP_CUSTOM,
    ]);

    $price = app(PricingService::class)->compute($plan, $product, 450000);

    // Provider cost context preserved for internal margin reporting...
    expect($price['provider_cost'])->toBe(8.5);
    expect($price['provider_currency'])->toBe('EUR');
    expect($price['exchange_rate'])->toBe(450000.0);
    expect($price['local_cost'])->toBe(3825000);

    // ...while customer pricing is the platform-controlled value.
    expect($price['hourly_price'])->toBe(850);
    expect($price['monthly_cap'])->toBe(399000);
});

it('uses the hourly rate as the initial order total for hourly modes', function () {
    $plan = new ProviderPlan([
        'provider_plan_id' => 'cpx21',
        'name' => 'CX21',
        'price_monthly' => 8.5,
        'price_hourly' => 0.012,
        'currency' => 'EUR',
    ]);

    $monthly = new Product(['billing_mode' => BillingMode::Monthly->value]);
    $hourly = new Product(['billing_mode' => BillingMode::Hourly->value, 'hourly_price_toman' => 850]);
    $capped = new Product(['billing_mode' => BillingMode::HourlyCapped->value, 'hourly_price_toman' => 850]);

    $service = app(PricingService::class);
    $monthlyPrice = $service->compute($plan, $monthly, 450000);
    $hourlyPrice = $service->compute($plan, $hourly, 450000);
    $cappedPrice = $service->compute($plan, $capped, 450000);

    expect($service->orderTotalToman($monthlyPrice, $monthly))->toBe($monthlyPrice['monthly_price']);
    expect($service->orderTotalToman($hourlyPrice, $hourly))->toBe(850);
    expect($service->orderTotalToman($cappedPrice, $capped))->toBe(850);
});
