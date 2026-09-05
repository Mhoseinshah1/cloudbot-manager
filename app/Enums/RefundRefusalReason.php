<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a refund did not happen.
 *
 * A refund creates money in a customer's wallet. Every one of these is a case
 * where doing that would be inventing it.
 */
enum RefundRefusalReason: string
{
    /**
     * The ledger holds no committed charge for this order.
     *
     * The most important one. An order's status is a string that code writes;
     * the ledger is the record of money actually moving. Refunding on the
     * former without the latter would credit a customer who never paid.
     */
    case NoCommittedCharge = 'no_committed_charge';

    /** The order is somewhere a refund makes no sense — provisioned, expired, cancelled. */
    case OrderNotRefundable = 'order_not_refundable';

    /** A server may exist. Reconciliation decides; this does not. */
    case OutcomeNotConfirmed = 'outcome_not_confirmed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
