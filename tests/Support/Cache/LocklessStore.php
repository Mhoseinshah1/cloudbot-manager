<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Contracts\Cache\Store;

/**
 * A cache store that cannot make locks.
 *
 * Every store Laravel ships with implements LockProvider, so a misconfigured
 * `locks` store cannot be produced by editing a driver name — and a branch no
 * test can reach is a branch nobody knows the behavior of. This is the
 * misconfiguration, made reachable: a perfectly working cache that simply has
 * no coordination to offer.
 */
final class LocklessStore implements Store
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function get($key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        $found = [];

        foreach ($keys as $key) {
            $found[$key] = $this->get($key);
        }

        return $found;
    }

    public function put($key, $value, $seconds): bool
    {
        $this->values[$key] = $value;

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1): int
    {
        $current = (int) ($this->values[$key] ?? 0);
        $this->values[$key] = $current + (int) $value;

        return $this->values[$key];
    }

    public function decrement($key, $value = 1): int
    {
        return $this->increment($key, -(int) $value);
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->values = [];

        return true;
    }

    public function getPrefix(): string
    {
        return '';
    }
}
