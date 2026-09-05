<?php

declare(strict_types=1);

namespace App\Telegram\Enums;

/**
 * The kinds of update this bot recognises.
 *
 * Telegram sends many more. Everything unrecognised collapses into `Other`
 * rather than being enumerated: a payload shape nobody has written a handler
 * for should be recorded and acknowledged, not routed somewhere by accident.
 */
enum TelegramUpdateType: string
{
    case Message = 'message';
    case CallbackQuery = 'callback_query';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
