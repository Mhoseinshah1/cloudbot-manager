<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use App\Cloud\Enums\ProviderActionStatus;
use App\Cloud\Enums\ProviderErrorCategory;
use DateTimeImmutable;

/**
 * A provider-side operation, normalized.
 *
 * Providers answer power and delete requests with a job to watch rather than a
 * finished result, so the contract returns one of these instead of a boolean.
 *
 * A failed operation carries a normalized category when the adapter can supply
 * one. It is optional and last because not every provider says anything useful
 * about why an action failed — and where none is available, the honest value is
 * null. The caller must not invent one: "unclassified" and "safe to repeat" are
 * different facts, and treating the first as the second is permission to send a
 * delete twice.
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
        /**
         * Why this operation failed, normalized. Null when unknown.
         *
         * Never a provider's own text and never its raw body: the code that
         * decides whether to repeat a destructive request must not read prose.
         */
        public ?ProviderErrorCategory $errorCategory = null,
    ) {}
}
