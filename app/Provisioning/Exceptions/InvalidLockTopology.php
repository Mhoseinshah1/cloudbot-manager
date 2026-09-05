<?php

declare(strict_types=1);

namespace App\Provisioning\Exceptions;

use RuntimeException;

/**
 * The deployment is configured so that a coordination lock could expire while
 * the provider call it covers is still running.
 *
 * Refused loudly at startup rather than tolerated, because the resulting bug is
 * silent: two workers each believe they hold the lock, and the only evidence is
 * a customer with two servers and one invoice.
 */
final class InvalidLockTopology extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
