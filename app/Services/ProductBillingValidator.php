<?php

namespace App\Services;

use App\Enums\BillingMode;
use App\Exceptions\InvalidProductBillingException;
use App\Models\Product;

/**
 * Domain-level validation of product billing configuration.
 *
 * This lives below the UI layer: OrderService (and any future API/Telegram
 * entry point) must run it, so invalid products are rejected even when the
 * Filament forms are bypassed.
 */
class ProductBillingValidator
{
    public function validate(Product $product): void
    {
        $mode = $product->billingMode();

        if ($mode === BillingMode::Hourly || $mode === BillingMode::HourlyCapped) {
            $this->positive($product, 'hourly_price_toman', 'hourly products require a positive hourly customer price');
        }

        if ($mode === BillingMode::HourlyCapped) {
            $this->positive($product, 'monthly_cap_toman', 'hourly_capped products require a positive monthly customer cap');

            $cap = (int) ($product->monthly_cap_toman ?? 0);
            $rate = (int) ($product->hourly_price_toman ?? 0);

            if ($cap > 0 && $rate > 0 && $cap < $rate) {
                throw InvalidProductBillingException::forProduct(
                    $product,
                    'the monthly cap cannot be lower than the hourly customer price'
                );
            }
        }

        if ($mode === BillingMode::Monthly) {
            if ($product->markup_strategy === Product::MARKUP_CUSTOM) {
                $this->positive($product, 'price_toman', 'monthly products with custom pricing require a positive monthly price');
            }

            if (in_array($product->markup_strategy, [Product::MARKUP_FIXED, Product::MARKUP_PERCENTAGE], true)
                && (float) ($product->markup_value ?? 0) < 0) {
                throw InvalidProductBillingException::forProduct($product, 'markup value cannot be negative');
            }

            if ($product->hourly_price_toman !== null || $product->monthly_cap_toman !== null) {
                throw InvalidProductBillingException::forProduct($product, 'monthly products cannot define hourly prices or a monthly cap');
            }
        }

        if ($product->hourly_price_toman !== null && (int) $product->hourly_price_toman <= 0) {
            throw InvalidProductBillingException::forProduct($product, 'hourly price must be a positive integer of toman');
        }

        if ($product->monthly_cap_toman !== null && (int) $product->monthly_cap_toman <= 0) {
            throw InvalidProductBillingException::forProduct($product, 'monthly cap must be a positive integer of toman');
        }
    }

    private function positive(Product $product, string $column, string $reason): void
    {
        $value = $product->{$column};

        if ($value === null || (int) $value <= 0) {
            throw InvalidProductBillingException::forProduct($product, $reason);
        }
    }
}
