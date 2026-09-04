<?php

declare(strict_types=1);

namespace App\Pricing\Data;

use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use Illuminate\Support\Carbon;

/**
 * What a sale would cost, and what it would earn, at one moment.
 *
 * Everything an order will need to snapshot, computed once and frozen. Later
 * phases record these values against the order so that a rate change tomorrow
 * cannot alter what a customer was charged today.
 *
 * Two kinds of number, kept apart deliberately:
 *
 *  - `sellingPriceToman` is customer money: a whole-Toman int, exactly the
 *    figure an operator configured. Nothing is added to it and nothing derives
 *    it from cost.
 *  - `providerCost`, `exchangeRate`, `convertedProviderCostToman` and
 *    `grossMarginToman` are exact decimals held as strings. The converted cost
 *    is provider cost times rate with no rounding whatsoever — fractional Toman
 *    is preserved, because the specification defines no rule for removing it
 *    and inventing one here would quietly change every margin figure.
 *
 * There is nothing from a provider's API in here. No response bodies, no
 * credentials — only the identities and the numbers.
 */
final readonly class PriceQuote
{
    public function __construct(
        public int $productId,
        public int $productLocationPriceId,
        public int $providerId,
        public string $providerCode,
        public int $providerPlanId,
        public string $providerPlanCode,
        public int $providerLocationId,
        public string $providerLocationCode,
        public ?int $defaultImageId,
        /** Exact decimal string: what the provider charges, in its own currency. */
        public string $providerCost,
        public string $providerCurrency,
        public int $exchangeRateId,
        /** Exact decimal string. */
        public string $exchangeRate,
        public Carbon $exchangeRateEffectiveFrom,
        /** Exact decimal string: providerCost × exchangeRate, unrounded. */
        public string $convertedProviderCostToman,
        /** Whole Toman, exactly as configured. */
        public int $sellingPriceToman,
        /** Exact decimal string: sellingPriceToman − convertedProviderCostToman. May be negative. */
        public string $grossMarginToman,
        public BillingMode $billingMode,
        public BillingCycle $billingCycle,
        public Carbon $evaluatedAt,
    ) {}

    /**
     * Whether this sale would lose money.
     *
     * Reported, never corrected. A negative margin is a configuration problem
     * for an operator to see and decide about; silently repricing would hide it.
     */
    public function isLossMaking(): bool
    {
        return str_starts_with($this->grossMarginToman, '-');
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_location_price_id' => $this->productLocationPriceId,
            'provider_id' => $this->providerId,
            'provider_code' => $this->providerCode,
            'provider_plan_id' => $this->providerPlanId,
            'provider_plan_code' => $this->providerPlanCode,
            'provider_location_id' => $this->providerLocationId,
            'provider_location_code' => $this->providerLocationCode,
            'default_image_id' => $this->defaultImageId,
            'provider_cost' => $this->providerCost,
            'provider_currency' => $this->providerCurrency,
            'exchange_rate_id' => $this->exchangeRateId,
            'exchange_rate' => $this->exchangeRate,
            'exchange_rate_effective_from' => $this->exchangeRateEffectiveFrom->toIso8601String(),
            'converted_provider_cost_toman' => $this->convertedProviderCostToman,
            'selling_price_toman' => $this->sellingPriceToman,
            'gross_margin_toman' => $this->grossMarginToman,
            'billing_mode' => $this->billingMode->value,
            'billing_cycle' => $this->billingCycle->value,
            'evaluated_at' => $this->evaluatedAt->toIso8601String(),
        ];
    }
}
