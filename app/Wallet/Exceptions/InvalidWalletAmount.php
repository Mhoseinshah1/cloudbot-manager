<?php

declare(strict_types=1);

namespace App\Wallet\Exceptions;

use InvalidArgumentException;

/**
 * The requested movement is not a coherent amount of money.
 */
final class InvalidWalletAmount extends InvalidArgumentException
{
    public static function zero(): self
    {
        return new self('A wallet movement must be a non-zero amount.');
    }

    public static function mustBePositive(string $operation): self
    {
        return new self("A {$operation} must be a positive amount.");
    }
}
