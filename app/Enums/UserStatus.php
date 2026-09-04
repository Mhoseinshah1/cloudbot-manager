<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Account standing.
 *
 * Suspension and banning are abuse controls: an automated VPS platform needs to
 * be able to stop someone buying or operating servers without deleting their
 * financial history.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';

    /**
     * Whether this status may hold a session at all.
     *
     * Applies to customers and administrators alike: a banned owner must not
     * keep operating the admin panel.
     */
    public function canAuthenticate(): bool
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
