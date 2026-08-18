<?php

namespace App\Enums;

/**
 * Lifecycle state of an hourly / hourly_capped VPS with respect to billing.
 *
 * Progression when charges cannot be collected:
 *
 *   active → low_balance → payment_due → grace → lifecycle_action_pending
 *
 * A successful charge (replenished balance) returns the server to active.
 * Grace is entered once and its timestamps are never rewritten on repeated
 * failed charges.
 */
enum BillingState: string
{
    case Active = 'active';

    case LowBalance = 'low_balance';

    case PaymentDue = 'payment_due';

    case Grace = 'grace';

    case LifecycleActionPending = 'lifecycle_action_pending';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::LowBalance => 'Low balance',
            self::PaymentDue => 'Payment due',
            self::Grace => 'Grace',
            self::LifecycleActionPending => 'Lifecycle action pending',
        };
    }
}
