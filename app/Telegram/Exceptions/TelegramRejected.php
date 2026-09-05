<?php

declare(strict_types=1);

namespace App\Telegram\Exceptions;

use App\Telegram\Enums\TelegramMethod;

/**
 * Telegram answered, and its answer was no.
 *
 * The `ok: false` envelope: a request Telegram understood and declined. Its
 * error code is kept as a fact; its description is kept only as scrubbed prose
 * for a human reading a log, and nothing branches on it.
 */
final class TelegramRejected extends TelegramApiException
{
    public function __construct(
        TelegramMethod $method,
        ?int $errorCode,
        string $description,
    ) {
        parent::__construct($method, "Telegram rejected {$method->value}: {$description}", $errorCode);
    }
}
