<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every business setting this system reads, named once.
 *
 * A setting is looked up by a string, and a mistyped string reads as "absent"
 * rather than as an error. For a kill switch that is the difference between
 * "sales are off" and "sales are on because nobody noticed the key was
 * `sales.enable`". Declaring them here makes the typo a fatal one.
 *
 * Keys are `<area>.<name>`, matching how permissions are named.
 */
enum SettingKey: string
{
    /**
     * Whether new sales may be quoted at all.
     *
     * The operator's off switch during an incident. Absent or malformed means
     * off: nothing about a missing row says selling is safe.
     */
    case SalesEnabled = 'sales.enabled';

    /**
     * How old an exchange rate may be and still price a new sale, in minutes.
     *
     * Absent or malformed blocks new sales rather than defaulting, because any
     * number invented here would be a business decision made by accident.
     */
    case FxMaxAgeMinutes = 'fx.max_age_minutes';

    public function type(): SettingType
    {
        return match ($this) {
            self::SalesEnabled => SettingType::Boolean,
            self::FxMaxAgeMinutes => SettingType::Integer,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
