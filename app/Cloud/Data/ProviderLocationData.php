<?php

declare(strict_types=1);

namespace App\Cloud\Data;

/**
 * A place a provider can put a server, normalized.
 */
final readonly class ProviderLocationData
{
    public function __construct(
        /** The provider's own identifier, as sent back to it later. */
        public string $providerLocationId,
        public string $name,
        public string $countryCode,
        public string $city,
        /** Whether the provider currently serves this location. */
        public bool $available,
        public SafeMetadata $metadata,
    ) {}
}
