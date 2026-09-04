<?php

declare(strict_types=1);

namespace App\Telegram\Enums;

/**
 * The Telegram API methods this application may call.
 *
 * A closed list, and the only thing ever interpolated into an API URL. The
 * alternative — building a URL from a method name a caller supplied — is how a
 * string that came from somewhere else ends up appended to a URL that carries
 * the bot token.
 *
 * Release 1.0's set, and nothing speculative.
 */
enum TelegramMethod: string
{
    case SendMessage = 'sendMessage';
    case EditMessageText = 'editMessageText';
    case AnswerCallbackQuery = 'answerCallbackQuery';
    case DeleteMessage = 'deleteMessage';
    case SetWebhook = 'setWebhook';
    case DeleteWebhook = 'deleteWebhook';
    case GetWebhookInfo = 'getWebhookInfo';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
