<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a ledger entry represents.
 *
 * The sign of the amount is not free: a credit and a refund always add, a debit
 * always subtracts. Only an administrative adjustment may go either way, which
 * is exactly why it is the one type that requires a privileged actor and a
 * written reason.
 */
enum WalletTransactionType: string
{
    /** Money added, typically by a settled payment. */
    case Credit = 'credit';

    /** Money spent. Always stored negative. */
    case Debit = 'debit';

    /** Money returned to the customer. */
    case Refund = 'refund';

    /** A privileged correction, in either direction. */
    case Adjustment = 'adjustment';

    /**
     * Whether this type must always increase the balance.
     */
    public function isAlwaysPositive(): bool
    {
        return in_array($this, [self::Credit, self::Refund], true);
    }

    public function isAlwaysNegative(): bool
    {
        return $this === self::Debit;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
