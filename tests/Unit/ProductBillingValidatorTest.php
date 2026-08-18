<?php

use App\Enums\BillingMode;
use App\Exceptions\InvalidProductBillingException;
use App\Models\Product;
use App\Services\ProductBillingValidator;

/**
 * Domain-level billing configuration validation. These tests prove that
 * invalid products are rejected below the UI layer, so the future Telegram
 * bot and direct service/API calls cannot bypass the checks.
 */
function billingProduct(array $attributes): Product
{
    return new Product(array_merge([
        'slug' => 'test-vps',
        'name' => 'Test VPS',
        'billing_mode' => BillingMode::Monthly->value,
        'markup_strategy' => Product::MARKUP_CUSTOM,
        'price_toman' => 399000,
    ], $attributes));
}

it('accepts a valid monthly product with custom pricing', function () {
    $product = billingProduct([]);

    expect(fn () => app(ProductBillingValidator::class)->validate($product))->not->toThrow(Throwable::class);
});

it('accepts a valid monthly product with fixed markup', function () {
    $product = billingProduct([
        'markup_strategy' => Product::MARKUP_FIXED,
        'markup_value' => 100000,
        'price_toman' => null,
    ]);

    expect(fn () => app(ProductBillingValidator::class)->validate($product))->not->toThrow(Throwable::class);
});

it('accepts a valid hourly product', function () {
    $product = billingProduct([
        'billing_mode' => BillingMode::Hourly->value,
        'hourly_price_toman' => 850,
    ]);

    expect(fn () => app(ProductBillingValidator::class)->validate($product))->not->toThrow(Throwable::class);
});

it('accepts a valid hourly_capped product', function () {
    $product = billingProduct([
        'billing_mode' => BillingMode::HourlyCapped->value,
        'hourly_price_toman' => 850,
        'monthly_cap_toman' => 399000,
    ]);

    expect(fn () => app(ProductBillingValidator::class)->validate($product))->not->toThrow(Throwable::class);
});

it('rejects hourly products without an hourly price', function () {
    app(ProductBillingValidator::class)->validate(billingProduct([
        'billing_mode' => BillingMode::Hourly->value,
    ]));
})->throws(InvalidProductBillingException::class);

it('rejects hourly_capped products without a monthly cap', function () {
    app(ProductBillingValidator::class)->validate(billingProduct([
        'billing_mode' => BillingMode::HourlyCapped->value,
        'hourly_price_toman' => 850,
    ]));
})->throws(InvalidProductBillingException::class);

it('rejects a monthly cap lower than the hourly rate', function () {
    app(ProductBillingValidator::class)->validate(billingProduct([
        'billing_mode' => BillingMode::HourlyCapped->value,
        'hourly_price_toman' => 850,
        'monthly_cap_toman' => 500,
    ]));
})->throws(InvalidProductBillingException::class);

it('rejects monthly products that define hourly prices or a monthly cap', function () {
    app(ProductBillingValidator::class)->validate(billingProduct([
        'billing_mode' => BillingMode::Monthly->value,
        'hourly_price_toman' => 850,
    ]));
})->throws(InvalidProductBillingException::class);

it('rejects non-positive hourly prices', function () {
    app(ProductBillingValidator::class)->validate(billingProduct([
        'billing_mode' => BillingMode::Hourly->value,
        'hourly_price_toman' => 0,
    ]));
})->throws(InvalidProductBillingException::class);

it('rejects non-positive monthly caps', function () {
    app(ProductBillingValidator::class)->validate(billingProduct([
        'billing_mode' => BillingMode::HourlyCapped->value,
        'hourly_price_toman' => 850,
        'monthly_cap_toman' => -100,
    ]));
})->throws(InvalidProductBillingException::class);

it('rejects custom-priced monthly products without a price', function () {
    app(ProductBillingValidator::class)->validate(billingProduct([
        'billing_mode' => BillingMode::Monthly->value,
        'markup_strategy' => Product::MARKUP_CUSTOM,
        'price_toman' => null,
    ]));
})->throws(InvalidProductBillingException::class);

it('rejects negative markup values', function () {
    app(ProductBillingValidator::class)->validate(billingProduct([
        'markup_strategy' => Product::MARKUP_PERCENTAGE,
        'markup_value' => -10,
        'price_toman' => null,
    ]));
})->throws(InvalidProductBillingException::class);
