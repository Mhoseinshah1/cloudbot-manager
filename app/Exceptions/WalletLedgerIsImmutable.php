<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to rewrite financial history.
 */
final class WalletLedgerIsImmutable extends RuntimeException
{
    public static function cannotUpdate(): self
    {
        return new self('Wallet ledger entries are immutable and cannot be updated.');
    }

    public static function cannotDelete(): self
    {
        return new self('Wallet ledger entries are immutable and cannot be deleted.');
    }
}
