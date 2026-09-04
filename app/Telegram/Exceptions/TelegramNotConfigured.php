<?php

declare(strict_types=1);

namespace App\Telegram\Exceptions;

use RuntimeException;

/**
 * The bot's credentials are missing or unusable.
 *
 * Raised rather than defaulted. There is no safe fallback for a bot token or a
 * webhook secret: one would make the application act as no bot at all, and the
 * other would accept unsigned traffic from anyone who found the URL.
 *
 * The message names which setting is missing and never its value.
 */
final class TelegramNotConfigured extends RuntimeException
{
    private function __construct(public readonly string $setting, string $message)
    {
        parent::__construct($message);
    }

    public static function missingBotToken(): self
    {
        return new self(
            'telegram.bot_token',
            'No Telegram bot token is configured, so the bot cannot act.',
        );
    }

    public static function missingWebhookSecret(): self
    {
        return new self(
            'telegram.webhook_secret',
            'No Telegram webhook secret is configured, so inbound updates cannot be authenticated.',
        );
    }

    public static function missingWebhookUrl(): self
    {
        return new self(
            'telegram.webhook_url',
            'No Telegram webhook URL is configured, so there is nowhere to point the bot.',
        );
    }
}
