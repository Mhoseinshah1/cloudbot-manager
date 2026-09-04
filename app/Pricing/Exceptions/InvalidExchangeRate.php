<?php

declare(strict_types=1);

namespace App\Pricing\Exceptions;

use InvalidArgumentException;

/**
 * A proposed rate could not be a rate.
 *
 * Refused before it reaches the database, which would refuse it too. Doing it
 * here gives the caller a usable message instead of a constraint violation.
 */
final class InvalidExchangeRate extends InvalidArgumentException
{
    public static function notPositive(string $rate): self
    {
        return new self("An exchange rate must be greater than zero; got {$rate}.");
    }

    public static function notANumber(string $rate): self
    {
        return new self('An exchange rate must be a decimal number.');
    }

    public static function currency(string $currency): self
    {
        return new self("'{$currency}' is not a three-letter currency code.");
    }
}
