<?php

namespace App\Services\Telegram;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache-backed conversation state for Telegram flows.
 *
 * Production defaults to Redis so state survives across webhook requests and
 * workers. Tests may explicitly use the array store.
 */
class TelegramStateService
{
    private int $ttl;

    private string $store;

    public function __construct()
    {
        $this->ttl = (int) config('telegram.state_ttl', 3600);
        $this->store = (string) config('telegram.state_store', 'redis');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $telegramUserId): ?array
    {
        $state = $this->cache()->get($this->key($telegramUserId));

        return is_array($state) ? $state : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function set(int $telegramUserId, array $state): void
    {
        $state['updated_at'] = now()->timestamp;
        $this->cache()->put($this->key($telegramUserId), $state, $this->ttl);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function update(int $telegramUserId, array $updates): void
    {
        $current = $this->get($telegramUserId) ?? [];
        $this->set($telegramUserId, array_merge($current, $updates));
    }

    public function clear(int $telegramUserId): void
    {
        $this->cache()->forget($this->key($telegramUserId));
    }

    public function has(int $telegramUserId): bool
    {
        return $this->get($telegramUserId) !== null;
    }

    private function cache(): Repository
    {
        return Cache::store($this->store);
    }

    private function key(int $telegramUserId): string
    {
        return "tg:state:{$telegramUserId}";
    }
}
