<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How long one paid period lasts.
 *
 * Distinct from the billing mode: Release 1.1's hourly-capped mode bills by the
 * hour but still settles on a monthly cycle, so the two answer different
 * questions. Release 1.0 has one value for both.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
