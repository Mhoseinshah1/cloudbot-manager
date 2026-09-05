<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use App\Cloud\Enums\ProviderPowerState;
use App\Cloud\Enums\ProviderServerStatus;

/**
 * A server as the provider sees it, normalized.
 *
 * Deliberately carries no credential. A root password, where one exists at all,
 * is returned once by the create call and stored encrypted; it is never part of
 * the ordinary shape of a server, because this object is read, logged and
 * compared constantly.
 */
final readonly class ProviderServerData
{
    public function __construct(
        public string $providerServerId,
        /** The token that ties this remote server to one local order. */
        public ?string $provisioningToken,
        public string $name,
        public string $providerPlanId,
        public string $providerLocationId,
        public string $providerImageId,
        public ProviderServerStatus $status,
        public ProviderPowerState $powerState,
        public ?string $ipv4,
        public ?string $ipv6,
        public SafeMetadata $metadata,
    ) {}
}
