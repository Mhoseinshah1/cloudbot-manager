<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a payment stands.
 *
 * Deliberately small. A payment either has not settled yet, has settled, was
 * rejected, or ran out of time. Order-related states arrive with orders.
 */
enum PaymentStatus: string
{
    /** Created, awaiting verification. No money has moved. */
    case Pending = 'pending';

    /** Settled. The wallet has been credited exactly once. */
    case Paid = 'paid';

    /** Explicitly rejected. Nothing was credited. */
    case Failed = 'failed';

    /** Not settled before its deadline. */
    case Expired = 'expired';

    /** Whether money has moved for this payment. */
    public function isSettled(): bool
    {
        return $this === self::Paid;
    }

    /** Whether this payment can still be settled. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
