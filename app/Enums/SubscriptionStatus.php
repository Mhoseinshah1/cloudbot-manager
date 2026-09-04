<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a subscription stands.
 *
 * Phase 7 only ever writes `active`, and only once, when a server is first
 * delivered. The remaining cases exist because reconciliation must be able to
 * stop a subscription renewing, and because declaring the vocabulary now is
 * cheaper than a later migration that widens a CHECK constraint under live data.
 *
 * Phase 11 owns the transitions between them.
 */
enum SubscriptionStatus: string
{
    /** Paid for and running. The only state Phase 11 may renew normally. */
    case Active = 'active';

    /** Past its period end, still recoverable. Phase 11 owns entry to this. */
    case Grace = 'grace';

    /** Stopped at the customer's request. */
    case Cancelled = 'cancelled';

    /** Ended. Kept as service history. */
    case Terminated = 'terminated';

    /**
     * Something is wrong enough that renewing would be a guess.
     *
     * Where a subscription lands when its server cannot be found remotely:
     * renewing it would charge a customer again for a machine that is gone.
     */
    case NeedsAttention = 'needs_attention';

    /**
     * Whether Phase 11 may renew this subscription without a person deciding.
     */
    public function isRenewable(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
