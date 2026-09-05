<?php

declare(strict_types=1);

namespace App\Telegram\Exceptions;

use App\Support\Secrets\SecretScrubber;
use App\Telegram\Enums\TelegramMethod;
use RuntimeException;

/**
 * A Telegram API call that did not succeed.
 *
 * Base class for the typed cases below. Callers decide from the type and from
 * the named fields — never by reading the message, which is prose written by
 * somebody else's server and may quote back what we sent it.
 *
 * Everything that reaches the message is scrubbed. That matters here more than
 * almost anywhere: every request URL contains the bot token, and an exception
 * is the single most likely object to end up in a log.
 */
class TelegramApiException extends RuntimeException
{
    public function __construct(
        public readonly TelegramMethod $method,
        string $message,
        public readonly ?int $errorCode = null,
    ) {
        // Scrubbed on the way in, not on the way out. An unscrubbed message
        // that is only redacted at the logger is one `getMessage()` away from
        // being printed somewhere else.
        parent::__construct(SecretScrubber::scrubText($message));
    }

    /** Whether trying the identical call again could plausibly work. */
    public function isRetryable(): bool
    {
        return false;
    }
}
