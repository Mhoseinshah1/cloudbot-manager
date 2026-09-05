<?php

declare(strict_types=1);

namespace App\Provisioning\Data;

use App\Enums\BillingCycle;
use App\Enums\BillingMode;

/**
 * Everything needed to build one server, read from the order's frozen
 * snapshots and from nothing else.
 *
 * The point of this object is what it is *not* allowed to consult. Today's
 * exchange rate, today's selling price, today's default image and today's
 * product state are all irrelevant to an order somebody already paid for: the
 * customer bought a specific machine at a specific price, and provisioning
 * delivers that, not whatever the catalog now says.
 *
 * So every field here comes out of `cost_snapshot` and `pricing_snapshot`,
 * which a database trigger has frozen since creation. There is no path from a
 * plan to PricingService.
 */
final readonly class ProvisioningPlan
{
    /**
     * @param  string  $providerCode  Selects the implementation in the registry.
     * @param  string  $providerPlanCode  Provider-native plan identifier.
     * @param  string  $providerLocationCode  Provider-native location identifier.
     * @param  string  $providerImageCode  Provider-native image identifier.
     * @param  array<string, mixed>  $planSnapshot
     * @param  array<string, mixed>  $imageSnapshot
     */
    public function __construct(
        public int $providerId,
        public string $providerCode,
        public int $providerPlanId,
        public string $providerPlanCode,
        public int $providerLocationId,
        public string $providerLocationCode,
        public int $providerImageId,
        public string $providerImageCode,
        public int $productId,
        public int $productLocationPriceId,
        public array $planSnapshot,
        public array $imageSnapshot,
        // Exact decimals, carried as strings. Never floats, never rounded.
        public string $providerCost,
        public string $providerCurrency,
        public string $exchangeRate,
        public string $localCostToman,
        public int $sellingPriceToman,
        public string $grossMarginToman,
        public BillingMode $billingMode,
        public BillingCycle $billingCycle,
    ) {}
}
