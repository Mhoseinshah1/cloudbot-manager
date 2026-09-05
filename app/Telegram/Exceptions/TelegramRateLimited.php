<?php

declare(strict_types=1);

namespace App\Telegram\Exceptions;

use App\Telegram\Enums\TelegramMethod;

/**
 * Telegram asked us to slow down.
 *
 * Carries the delay it asked for, already clamped. The caller releases its job
 * for that long; nothing sleeps inside the transport, because the interactive
 * worker holding a thread for five minutes is how one rate-limited customer
 * stops every other customer's buttons from working.
 */
final class TelegramRateLimited extends TelegramApiException
{
    public function __construct(
        TelegramMethod $method,
        public readonly int $retryAfterSeconds,
        string $message = 'Telegram is rate limiting this bot.',
    ) {
        parent::__construct($method, $message, 429);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
