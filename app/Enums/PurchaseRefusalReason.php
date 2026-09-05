<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an abuse control refused a purchase.
 *
 * Separate from the order refusals so that a Telegram flow can say something
 * useful — "you already have three servers" is a different sentence from "we
 * are not configured to sell right now" — without reading an exception message
 * to work out which it was.
 */
enum PurchaseRefusalReason: string
{
    /** The customer already holds as many servers as they may. */
    case ActiveServerLimitReached = 'active_server_limit_reached';

    /** They have created too many orders too quickly. */
    case PurchaseVelocityExceeded = 'purchase_velocity_exceeded';

    /**
     * The limits themselves are missing or unreadable.
     *
     * Blocks the sale. A system selling servers with no abuse ceiling because
     * nobody configured one behaves exactly like a system with no ceiling.
     */
    case LimitsNotConfigured = 'limits_not_configured';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
