<?php

declare(strict_types=1);

namespace App\Telegram\Exceptions;

use App\Telegram\Enums\TelegramMethod;

/**
 * Telegram refused to deliver to this chat.
 *
 * Almost always a customer who blocked the bot. Never retried: the answer will
 * not change until they unblock it, and a job that keeps trying turns one
 * customer's choice into an endless failed-job loop.
 *
 * The chat it happened to is carried explicitly, because the account to mark is
 * the one Telegram refused — not whichever account a username currently points
 * at.
 */
final class TelegramForbidden extends TelegramApiException
{
    public function __construct(
        TelegramMethod $method,
        public readonly ?int $chatId = null,
        string $message = 'Telegram refused to deliver to this chat.',
    ) {
        parent::__construct($method, $message, 403);
    }
}
