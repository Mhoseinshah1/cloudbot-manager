<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a rate came from.
 *
 * Recorded because the answer changes how much a rate can be trusted and who
 * is accountable for it. Release 1.0 writes only `manual`: an administrator
 * enters the rate and is named on the row. The other two cases exist because
 * the column must be able to describe a rate that arrived another way once
 * such a source is actually built and verified.
 */
enum ExchangeRateSource: string
{
    /** Entered by a named administrator. The only source Release 1.0 writes. */
    case Manual = 'manual';

    /** Taken from a provider's own published rate. */
    case Provider = 'provider';

    /** Fetched from an external feed. No such feed exists yet. */
    case External = 'external';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
