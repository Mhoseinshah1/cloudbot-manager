<?php

namespace App\Exceptions;

use Exception;

class ProviderException extends Exception
{
    public static function insufficientBalance(string $message): self
    {
        return new self($message);
    }

    public static function unavailable(string $resource, string $identifier): self
    {
        return new self("{$resource} '{$identifier}' is not available at the provider.");
    }

    public static function rateLimited(string $message = 'Provider rate limit exceeded.'): self
    {
        return new self($message);
    }
}
