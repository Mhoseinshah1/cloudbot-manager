<?php

declare(strict_types=1);

namespace App\Telegram\Exceptions;

use InvalidArgumentException;

/**
 * Something tried to remember a thing conversation state must not hold.
 *
 * Refused loudly rather than quietly scrubbed. A caller storing a credential or
 * a model in a customer's conversation notes has misunderstood what this store
 * is for, and silently dropping the value would leave them believing it was
 * kept — which is worse than the write failing.
 *
 * The message names the key, never the value.
 */
final class UnsafeConversationState extends InvalidArgumentException
{
    public static function becauseKeyNamesASecret(string $key): self
    {
        return new self("Conversation state cannot hold `{$key}`: its name says it is a credential.");
    }

    public static function becauseValueLooksLikeASecret(string $key): self
    {
        return new self("Conversation state cannot hold `{$key}`: its value looks like a credential.");
    }

    public static function becauseValueIsNotScalar(string $key): self
    {
        return new self(
            "Conversation state cannot hold `{$key}`: only simple values are kept, never objects or nested data."
        );
    }

    public static function becauseKeyIsNotNamed(): self
    {
        return new self('Conversation state must be a map of named values.');
    }
}
