<?php

declare(strict_types=1);

namespace App\Pricing\Exceptions;

use RuntimeException;

/**
 * Someone without the right to set exchange rates tried to set one.
 *
 * The rate decides what every foreign-currency cost is worth in Toman, so it
 * decides margin on every sale. It is guarded by `settings.manage` — the same
 * permission as the other business controls — which support does not hold.
 */
final class UnauthorizedRateChange extends RuntimeException
{
    public static function forActor(): self
    {
        return new self('Recording an exchange rate requires an active administrator holding settings.manage.');
    }
}
