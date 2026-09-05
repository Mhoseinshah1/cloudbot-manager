<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the local record says about a server we sold.
 *
 * Local state, not the provider's. The two disagree regularly — that is the
 * whole reason reconciliation exists — and this column records what this system
 * believes and is willing to bill for, which is a different question from what
 * the provider happened to report a moment ago.
 *
 * Deliberately small. Release 1.0 delivers, loses track of, pauses and ends
 * servers; anything finer belongs to the phase that can act on it.
 */
enum ServerStatus: string
{
    /** Delivered and believed to exist. The only state that bills normally. */
    case Active = 'active';

    /** A completed inventory read found no remote counterpart. */
    case Missing = 'missing';

    /** Service paused. Phase 11 owns when this happens. */
    case Suspended = 'suspended';

    /** Ended. The row is kept as history; it is never deleted. */
    case Terminated = 'terminated';

    /** Something is contradictory enough that a person must look. */
    case NeedsAttention = 'needs_attention';

    /**
     * Whether this server should take part in ordinary renewal.
     *
     * A missing or contradictory server must not quietly renew: charging again
     * for a machine nobody can find is the failure this state exists to stop.
     */
    public function isBillable(): bool
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
