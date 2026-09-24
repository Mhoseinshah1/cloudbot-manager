<?php

declare(strict_types=1);

namespace App\Cloud\Sync;

/**
 * What one pricing sync did, for the command to print.
 */
final class PricingSyncReport
{
    public int $pairsReceived = 0;

    public int $costsUpdated = 0;

    public int $costsCleared = 0;

    public function __construct(public readonly string $providerCode) {}
}
