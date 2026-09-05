<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * An invoice's standing.
 *
 * An invoice for a settled payment is issued the moment the money moves, so
 * there is no unpaid state to represent yet.
 */
enum InvoiceStatus: string
{
    case Issued = 'issued';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
