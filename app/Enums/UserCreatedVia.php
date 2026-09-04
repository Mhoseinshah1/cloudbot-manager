<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the account came into existence.
 *
 * Kept because the origin changes what is required of a record: an account
 * created from Telegram legitimately has no email or password, while one
 * created by an administrator must have both.
 */
enum UserCreatedVia: string
{
    case Telegram = 'telegram';
    case Admin = 'admin';
    case Web = 'web';
    case Import = 'import';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
