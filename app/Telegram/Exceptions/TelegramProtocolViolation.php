<?php

declare(strict_types=1);

namespace App\Telegram\Exceptions;

use App\Telegram\Enums\TelegramMethod;

/**
 * Something answered, but not in Telegram's shape.
 *
 * A 200 with a body that is not the documented envelope is not success. Most
 * often it is a proxy, a captive portal or an error page in front of the real
 * API, and treating it as success would report a message delivered that nobody
 * received. Fails closed rather than guessing at the body.
 */
final class TelegramProtocolViolation extends TelegramApiException
{
    public function __construct(TelegramMethod $method, string $reason)
    {
        parent::__construct($method, "Telegram returned something unreadable for {$method->value}: {$reason}");
    }
}
