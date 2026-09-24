<?php

declare(strict_types=1);

namespace App\Cloud\Sync;

/**
 * What one catalog sync did, for the command to print.
 *
 * Counts only. A sync report is read in a terminal and copied into an incident
 * note, so it carries no provider payloads and nothing that could be a secret.
 */
final class CatalogSyncReport
{
    public int $locations = 0;

    public int $locationsWithdrawn = 0;

    public int $plans = 0;

    public int $images = 0;

    public int $imagesDeprecated = 0;

    public function __construct(public readonly string $providerCode) {}
}
