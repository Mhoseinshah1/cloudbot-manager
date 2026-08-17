<?php

namespace App\Exceptions;

class ProviderRateLimitException extends ProviderException
{
    public function __construct(string $message, public ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
