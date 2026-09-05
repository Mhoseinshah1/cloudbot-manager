<?php

declare(strict_types=1);

namespace App\Cloud\Data;

/**
 * A server size offered by a provider, normalized.
 *
 * Prices are the provider's own cost, in the provider's currency.
 */
final readonly class ProviderPlanData
{
    public function __construct(
        public string $providerPlanId,
        public string $name,
        public int $vcpu,
        public int $ramMb,
        public int $diskGb,
        public ?int $bandwidthGb,
        public ProviderPrice $monthlyPrice,
        /** Null where the provider does not price by the hour. */
        public ?ProviderPrice $hourlyPrice,
        public SafeMetadata $metadata,
    ) {}
}
