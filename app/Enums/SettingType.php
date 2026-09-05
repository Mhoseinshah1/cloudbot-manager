<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a setting's stored text is interpreted.
 *
 * Settings are held as text so the table stays uniform; the type says how to
 * read it back. Without this a "0" could mean the string zero, the number zero
 * or false, and a kill switch that reads wrong is a real outage.
 */
enum SettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Float = 'float';
    case Json = 'json';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
