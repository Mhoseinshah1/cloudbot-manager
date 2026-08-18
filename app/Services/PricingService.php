<?php

namespace App\Services;

use App\Enums\BillingMode;
use App\Models\Product;
use App\Models\ProviderPlan;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Computes customer selling prices from provider catalog cost + markup.
 *
 * Provider cost and customer price are intentionally decoupled:
 * - provider monthly/hourly cost comes from the provider catalog
 * - customer prices are controlled by the platform (markup strategy or
 *   explicit toman values) and never expose provider cost or margin
 *
 * All customer money values are integer toman.
 */
class PricingService
{
    /**
     * Default exchange rate used when no explicit rate is provided
     * (toman per provider currency unit).
     */
    public const DEFAULT_EXCHANGE_RATE = 450000.0;

    /**
     * @return array{
     *     provider_cost: float,
     *     provider_hourly_cost: float,
     *     provider_currency: string,
     *     exchange_rate: float,
     *     local_cost: int,
     *     local_hourly_cost: int,
     *     selling_price: int,
     *     monthly_price: int,
     *     hourly_price: int,
     *     monthly_cap: int|null,
     *     gross_margin: int,
     *     monthly_gross_margin: int,
     *     hourly_gross_margin: int,
     *     billing_mode: string,
     * }
     */
    public function compute(ProviderPlan $plan, Product $product, ?float $exchangeRate = null): array
    {
        $rate = $exchangeRate ?? $this->defaultExchangeRate();
        $currency = $plan->currency;

        $providerCost = (float) $plan->price_monthly;
        $providerHourlyCost = (float) ($plan->price_hourly ?? 0.0);
        $localCost = (int) round($providerCost * $rate);
        $localHourlyCost = (int) round($providerHourlyCost * $rate);

        $monthlyPrice = $this->applyMarkup($product, $localCost);

        // Explicit customer hourly price wins; otherwise derive from markup.
        $hourlyPrice = $product->hourly_price_toman !== null
            ? (int) $product->hourly_price_toman
            : $this->applyMarkup($product, $localHourlyCost);

        $monthlyCap = null;

        if ($product->billingMode() === BillingMode::HourlyCapped) {
            $monthlyCap = $product->monthly_cap_toman !== null
                ? (int) $product->monthly_cap_toman
                : $monthlyPrice;
        }

        $primaryPrice = match ($product->billingMode()) {
            BillingMode::Hourly, BillingMode::HourlyCapped => $hourlyPrice,
            default => $monthlyPrice,
        };

        $primaryLocalCost = $product->billingMode()->isHourly() ? $localHourlyCost : $localCost;

        return [
            'provider_cost' => $providerCost,
            'provider_hourly_cost' => $providerHourlyCost,
            'provider_currency' => $currency,
            'exchange_rate' => $rate,
            'local_cost' => $localCost,
            'local_hourly_cost' => $localHourlyCost,
            'selling_price' => $primaryPrice,
            'monthly_price' => $monthlyPrice,
            'hourly_price' => $hourlyPrice,
            'monthly_cap' => $monthlyCap,
            'gross_margin' => $primaryPrice - $primaryLocalCost,
            'monthly_gross_margin' => $monthlyPrice - $localCost,
            'hourly_gross_margin' => $hourlyPrice - $localHourlyCost,
            'billing_mode' => $product->billingMode()->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $price
     */
    public function orderTotalToman(array $price, Product $product): int
    {
        return match ($product->billingMode()) {
            BillingMode::Hourly, BillingMode::HourlyCapped => (int) $price['hourly_price'],
            default => (int) $price['selling_price'],
        };
    }

    public function defaultExchangeRate(): float
    {
        try {
            $rate = Setting::get('exchange_rate_eur_toman');

            if ($rate !== null) {
                return (float) $rate;
            }

            $this->warnExchangeRateFallback('setting_missing');
        } catch (\Throwable $e) {
            $this->warnExchangeRateFallback('setting_read_failed', $e->getMessage());
        }

        return self::DEFAULT_EXCHANGE_RATE;
    }

    private function warnExchangeRateFallback(string $reason, ?string $error = null): void
    {
        $context = [
            'reason' => $reason,
            'fallback_rate' => self::DEFAULT_EXCHANGE_RATE,
        ];

        if ($error !== null) {
            $context['error'] = $error;
        }

        Log::warning('Exchange-rate setting unavailable; using fallback rate.', $context);

        // The project currently has no dedicated admin-alert transport. Emit a
        // rate-limited critical log entry that production log monitoring can
        // page on without flooding admins on every pricing calculation.
        if (Cache::add('pricing:exchange-rate-fallback-admin-alert', true, now()->addHour())) {
            Log::critical('ADMIN ALERT: fallback EUR→toman exchange rate is active.', $context);
        }
    }

    private function applyMarkup(Product $product, int $localCost): int
    {
        return match ($product->markup_strategy) {
            Product::MARKUP_FIXED => $localCost + (int) round((float) $product->markup_value),
            Product::MARKUP_PERCENTAGE => (int) round($localCost * (1 + ((float) $product->markup_value / 100))),
            Product::MARKUP_CUSTOM => (int) ($product->price_toman ?? $localCost),
            default => $localCost,
        };
    }
}
