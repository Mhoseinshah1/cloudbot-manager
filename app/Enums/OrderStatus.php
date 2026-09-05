<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an order stands.
 *
 * The normal path is pending → awaiting_payment → paid → provisioning →
 * provisioned. Everything else records a way that path stopped, and each
 * exceptional state means something different to the money: `expired` and
 * `cancelled` never took any, `failed` took it and has not given it back,
 * `refunded` took it and has.
 *
 * Which moves between these are allowed is not declared here. That is
 * behaviour, it belongs to the state machine, and putting the graph beside
 * the vocabulary would invite reading it without going through the layer that
 * enforces it.
 */
enum OrderStatus: string
{
    /** Created. Nothing is owed and nothing is reserved. */
    case Pending = 'pending';

    /** The customer has been asked to pay. No money has moved. */
    case AwaitingPayment = 'awaiting_payment';

    /** The customer's funds are committed. Nothing has been provisioned. */
    case Paid = 'paid';

    /** A provisioning attempt is in flight. Phase 7 owns what happens here. */
    case Provisioning = 'provisioning';

    /** A server exists and belongs to the customer. */
    case Provisioned = 'provisioned';

    /** It will not be provisioned. Money may still be owed back. */
    case Failed = 'failed';

    /** Failed, and the money has been returned. */
    case Refunded = 'refunded';

    /** Never paid for in time. */
    case Expired = 'expired';

    /** A person has to look at this one. */
    case NeedsAttention = 'needs_attention';

    /** Called off before payment. */
    case Cancelled = 'cancelled';

    /**
     * Whether the order is finished for Release 1.0 purposes.
     *
     * A terminal order is not reopened; a later phase that genuinely needs to
     * move out of one adds that edge to the state machine, with tests, rather
     * than reinterpreting this.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Provisioned, self::Refunded, self::Expired, self::Cancelled], strict: true);
    }

    /** Whether the customer's funds are committed to this order. */
    public function isFunded(): bool
    {
        return in_array(
            $this,
            [self::Paid, self::Provisioning, self::Provisioned, self::Failed, self::NeedsAttention],
            strict: true,
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
