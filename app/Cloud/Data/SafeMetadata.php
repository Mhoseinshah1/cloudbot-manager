<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use App\Support\Secrets\SecretScrubber;

/**
 * Provider metadata that is safe to keep.
 *
 * Provider responses are untrusted external data. Rather than storing one and
 * hoping nothing sensitive is in it, callers name the keys they want and
 * everything else is dropped. Scrubbing runs afterwards as a second line of
 * defence, not as permission to persist a whole response.
 */
final readonly class SafeMetadata
{
    /**
     * @param  array<string, scalar|null>  $values
     */
    private function __construct(public array $values) {}

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Keep only the named keys, and only if their value is a simple scalar.
     *
     * Nested structures are refused rather than walked: a nested blob is how a
     * whole provider response slips in one key at a time.
     *
     * @param  array<array-key, mixed>  $source
     * @param  list<string>  $allowed
     */
    public static function pick(array $source, array $allowed): self
    {
        $kept = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $source)) {
                continue;
            }

            // A key that names a secret is never safe, whoever allowed it.
            if (SecretScrubber::isSecretKey($key)) {
                continue;
            }

            $value = $source[$key];

            if ($value !== null && ! is_scalar($value)) {
                continue;
            }

            $kept[$key] = is_string($value) ? SecretScrubber::scrubText($value) : $value;
        }

        return new self($kept);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
