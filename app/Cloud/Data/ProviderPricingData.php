<?php

declare(strict_types=1);

namespace App\Cloud\Data;

/**
 * What a plan costs in a specific location.
 *
 * Separate from the plan because providers price the same size differently by
 * region, and a single price on the plan would quietly be wrong somewhere.
 */
final readonly class ProviderPricingData
{
    public function __construct(
        public string $providerPlanId,
        public string $providerLocationId,
        public ProviderPrice $monthlyPrice,
        public ?ProviderPrice $hourlyPrice,
    ) {}
}
