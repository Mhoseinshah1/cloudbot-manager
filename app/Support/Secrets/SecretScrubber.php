<?php

declare(strict_types=1);

namespace App\Support\Secrets;

/**
 * The single definition of what counts as a secret, and how to remove it.
 *
 * Two subsystems must never emit credentials: logs and the audit trail. They
 * share this class rather than each carrying its own list, because two lists
 * drift, and the one that drifts is the one that leaks.
 */
final class SecretScrubber
{
    public const REDACTED = '[redacted]';

    /**
     * Key names whose value is always a secret, matched case-insensitively
     * anywhere in the key (so `root_password` and `providerToken` both match).
     */
    private const SECRET_KEY_PATTERN = '/(pass(word|wd)?|secret|token|authorization|auth[_-]?key|api[_-]?key|credential|private[_-]?key|recovery[_-]?code)/i';

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
     * How far into a structure this will look.
     *
     * Anything nested deeper is redacted rather than passed through: what has
     * not been inspected cannot be shown to be free of credentials, and a
     * payload that deep is a wholesale response rather than the handful of
     * named facts these callers are meant to record.
     */
    private const MAX_DEPTH = 8;

    public static function isSecretKey(string $key): bool
    {
        return preg_match(self::SECRET_KEY_PATTERN, $key) === 1;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function scrub(array $values, int $depth = 0): array
    {
        $scrubbed = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $scrubbed[$key] = self::REDACTED;

                continue;
            }

            $scrubbed[$key] = match (true) {
                is_array($value) => $depth + 1 >= self::MAX_DEPTH
                    ? self::REDACTED
                    : self::scrub($value, $depth + 1),
                is_string($value) => self::scrubText($value),
                default => $value,
            };
        }

        return $scrubbed;
    }

    public static function scrubText(string $text): string
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
