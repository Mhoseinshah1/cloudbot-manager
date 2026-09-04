<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use App\Cloud\Enums\ProviderActionStatus;
use DateTimeImmutable;

/**
 * A provider-side operation, normalized.
 *
 * Providers answer power and delete requests with a job to watch rather than a
 * finished result, so the contract returns one of these instead of a boolean.
 */
final readonly class ProviderActionData
{
    public function __construct(
        public string $providerActionId,
        /** What was asked for: power_on, power_off, reboot, delete. */
        public string $command,
        public ProviderActionStatus $status,
        public ?string $providerServerId,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public SafeMetadata $metadata,
    ) {}
}
