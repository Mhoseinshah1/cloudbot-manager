<?php

declare(strict_types=1);

namespace App\Provisioning\Data;

/**
 * What one inventory sweep found.
 *
 * Counts and a failure reason, so a command can exit non-zero on a read it
 * could not complete. Distinguishing "nothing was wrong" from "we could not
 * look" is the whole point: the second must never be reported as the first.
 */
final class InventoryReport
{
    public int $localChecked = 0;

    public int $drifted = 0;

    public int $missing = 0;

    public int $orphans = 0;

    public ?string $failure = null;

    public function __construct(public readonly string $providerCode) {}

    public function fail(string $reason): void
    {
        $this->failure = $reason;
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
