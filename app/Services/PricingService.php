<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProviderPlan;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class PricingService
{
    /**
     * Default exchange rate used when no explicit rate is provided
     * (toman per provider currency unit).
     */
    public const DEFAULT_EXCHANGE_RATE = 450000.0;

    /**
     * @return array{provider_cost: float, provider_currency: string, exchange_rate: float, local_cost: int, selling_price: int, gross_margin: int}
     */
    public function compute(ProviderPlan $plan, Product $product, ?float $exchangeRate = null): array
    {
        $rate = $exchangeRate ?? $this->defaultExchangeRate();

        $providerCost = (float) $plan->price_monthly;
        $currency = $plan->currency;
        $localCost = (int) round($providerCost * $rate);

        $sellingPrice = match ($product->markup_strategy) {
            Product::MARKUP_FIXED => $localCost + (int) round((float) $product->markup_value),
            Product::MARKUP_PERCENTAGE => (int) round($localCost * (1 + ((float) $product->markup_value / 100))),
            Product::MARKUP_CUSTOM => (int) ($product->price_toman ?? $localCost),
            default => $localCost,
        };

        return [
            'provider_cost' => $providerCost,
            'provider_currency' => $currency,
            'exchange_rate' => $rate,
            'local_cost' => $localCost,
            'selling_price' => $sellingPrice,
            'gross_margin' => $sellingPrice - $localCost,
        ];
    }

    public function defaultExchangeRate(): float
    {
        try {
            $rate = Setting::get('exchange_rate_eur_toman');

            return $rate !== null ? (float) $rate : self::DEFAULT_EXCHANGE_RATE;
        } catch (\Throwable $e) {
            Log::debug('Could not read exchange rate setting, using default.', ['error' => $e->getMessage()]);

            return self::DEFAULT_EXCHANGE_RATE;
        }
    }
}
