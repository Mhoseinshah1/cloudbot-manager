<?php

declare(strict_types=1);

namespace App\Telegram\Enums;

/**
 * Where a message came from.
 *
 * This matters more than it looks. Only a private chat is a customer talking to
 * the bot; a group or channel is somewhere the bot happens to be, and letting
 * one of those set a customer's stored chat id would redirect their invoices
 * and server credentials into a room full of strangers.
 */
enum TelegramChatType: string
{
    case Private = 'private';
    case Group = 'group';
    case Supergroup = 'supergroup';
    case Channel = 'channel';
    case Unknown = 'unknown';

    public static function fromTelegram(?string $type): self
    {
        return self::tryFrom((string) $type) ?? self::Unknown;
    }

    /** Whether this is a customer's own conversation with the bot. */
    public function isPrivate(): bool
    {
        return $this === self::Private;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
