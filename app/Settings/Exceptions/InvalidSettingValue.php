<?php

declare(strict_types=1);

namespace App\Settings\Exceptions;

use App\Enums\SettingKey;
use App\Enums\SettingType;
use InvalidArgumentException;

/**
 * A value was offered for a setting it cannot be.
 *
 * Refused rather than coerced, because PHP's coercion rules are exactly wrong
 * here: the string "false" is truthy, so a caller passing it for a kill switch
 * would turn selling *on* using the word for off. No conversion is applied to
 * any value reaching a business control — the caller passes the declared type
 * or the write does not happen.
 */
final class InvalidSettingValue extends InvalidArgumentException
{
    private function __construct(
        public readonly SettingKey $key,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function wrongType(SettingKey $key, mixed $value): self
    {
        $expected = match ($key->type()) {
            SettingType::Boolean => 'a bool',
            SettingType::Integer => 'an int',
            SettingType::Float => 'a float',
            SettingType::Json => 'an array',
            SettingType::String => 'a string',
        };

        return new self(
            $key,
            sprintf('%s expects %s, got %s.', $key->value, $expected, get_debug_type($value)),
        );
    }

    public static function outOfRange(SettingKey $key, string $requirement): self
    {
        return new self($key, "{$key->value} must be {$requirement}.");
    }
}
