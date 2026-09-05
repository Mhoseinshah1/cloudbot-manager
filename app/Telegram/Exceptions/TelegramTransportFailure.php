<?php

declare(strict_types=1);

namespace App\Telegram\Exceptions;

use App\Telegram\Enums\TelegramMethod;

/**
 * The call never got an answer.
 *
 * A timeout, a refused connection, DNS. Retryable, because it says nothing
 * about the request itself — but note that for a send this leaves the same
 * uncertainty every network boundary does: the message may or may not have
 * gone out. In this phase that is acceptable, because the only thing at stake
 * is a duplicated menu; nothing here spends money.
 */
final class TelegramTransportFailure extends TelegramApiException
{
    public function __construct(TelegramMethod $method, string $message)
    {
        parent::__construct($method, "Could not reach Telegram for {$method->value}: {$message}");
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
