<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes credentials from log records before they are written.
 *
 * This system handles provider API tokens, a Telegram bot token, database
 * passwords and server root passwords. None of them may ever reach a log file,
 * so redaction happens centrally here rather than being left to each call site
 * to remember.
 *
 * Two things are redacted: context and extra entries whose *key* names a
 * secret, and credential patterns appearing inside message or value *text*.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    public const REDACTED = '[redacted]';

    /**
     * Key names whose value is always a secret, matched case-insensitively
     * anywhere in the key (so `root_password` and `providerToken` both match).
     */
    private const SECRET_KEY_PATTERN = '/(pass(word|wd)?|secret|token|authorization|auth[_-]?key|api[_-]?key|credential|private[_-]?key)/i';

    /**
     * Credential shapes that appear inside free text.
     *
     * @var list<string>
     */
    private const SECRET_VALUE_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9._\-]+/i',           // HTTP Authorization headers
        '/\bbot\d{5,}:[A-Za-z0-9_\-]{20,}/i',       // Telegram bot tokens in API URLs
        '/\bbase64:[A-Za-z0-9+\/=]{30,}/',          // Laravel APP_KEY
    ];

    /**
     * Guards against unbounded recursion on deeply nested or cyclic context.
     */
    private const MAX_DEPTH = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactText($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redactArray(array $values, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return $values;
        }

        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = match (true) {
                is_array($value) => $this->redactArray($value, $depth + 1),
                is_string($value) => $this->redactText($value),
                default => $value,
            };
        }

        return $redacted;
    }

    private function redactText(string $text): string
    {
        foreach (self::SECRET_VALUE_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::REDACTED, $text);

            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        return $text;
    }
}
