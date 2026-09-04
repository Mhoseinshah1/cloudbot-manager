<?php

declare(strict_types=1);

namespace App\Cloud\Data;

/**
 * An operating system image, normalized.
 */
final readonly class ProviderImageData
{
    public function __construct(
        public string $providerImageId,
        public string $name,
        public string $osFamily,
        public string $version,
        public string $architecture,
        /** Still listed, but the provider advises against new use. */
        public bool $deprecated,
        public SafeMetadata $metadata,
    ) {}
}
