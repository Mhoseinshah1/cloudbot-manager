<?php

namespace App\Enums;

/**
 * Customer billing modes supported by the platform.
 *
 * Products explicitly declare their billing mode; it is never inferred
 * from provider catalog pricing.
 */
enum BillingMode: string
{
    case Monthly = 'monthly';

    case Hourly = 'hourly';

    case HourlyCapped = 'hourly_capped';

    public function isHourly(): bool
    {
        return $this === self::Hourly || $this === self::HourlyCapped;
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Hourly => 'Hourly',
            self::HourlyCapped => 'Hourly (capped)',
        };
    }
}
