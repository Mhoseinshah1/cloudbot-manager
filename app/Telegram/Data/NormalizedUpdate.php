<?php

declare(strict_types=1);

namespace App\Telegram\Data;

use App\Telegram\Enums\TelegramAction;
use App\Telegram\Enums\TelegramChatType;
use App\Telegram\Enums\TelegramUpdateType;

/**
 * What one Telegram delivery amounts to, once everything unsafe is gone.
 *
 * Every field is either a number, or a value drawn from a closed enum, or a
 * short string this system bounded itself. Nothing a stranger typed survives
 * into this object — which is what makes it safe to store, log and hand to a
 * handler without asking again where each value came from.
 */
final readonly class NormalizedUpdate
{
    /**
     * @param  array<string, scalar|null>  $profile  Whitelisted display metadata.
     */
    public function __construct(
        public int $updateId,
        public TelegramUpdateType $type,
        public TelegramChatType $chatType,
        public ?int $telegramUserId,
        public ?int $telegramChatId,
        public ?int $messageId,
        public ?string $callbackQueryId,
        public TelegramAction $action,
        public array $profile = [],
        public bool $isBot = false,
    ) {}

    /** Whether this update can be attributed to a customer at all. */
    public function hasIdentity(): bool
    {
        return $this->telegramUserId !== null && ! $this->isBot;
    }

    /** Whether this came from a customer's own conversation with the bot. */
    public function isPrivate(): bool
    {
        return $this->chatType->isPrivate();
    }

    /**
     * The facts worth keeping alongside the row.
     *
     * @return array<string, scalar|null>
     */
    public function metadata(): array
    {
        return [
            'is_bot' => $this->isBot,
            ...$this->profile,
        ];
    }
}
