<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived Redis conversation state for Telegram flows.
 *
 * Stores the user's progress through buy/location/plan selection flows.
 * Expired state results in a friendly restart message — never an error.
 * Financial truth is never stored only in Redis.
 */
class TelegramStateService
{
    private int $ttl;

    public function __construct()
    {
        $this->ttl = config('telegram.state_ttl', 3600);
    }

    /**
     * Get the current state for a Telegram user.
     *
     * @return array<string, mixed>|null
     */
    public function get(int $telegramUserId): ?array
    {
        $state = Cache::store('array')->get($this->key($telegramUserId));

        if (! is_array($state)) {
            return null;
        }

        return $state;
    }

    /**
     * Set state for a Telegram user.
     *
     * @param  array<string, mixed>  $state
     */
    public function set(int $telegramUserId, array $state): void
    {
        $state['updated_at'] = now()->timestamp;

        Cache::store('array')->put($this->key($telegramUserId), $state, $this->ttl);
    }

    /**
     * Merge partial updates into existing state.
     *
     * @param  array<string, mixed>  $updates
     */
    public function update(int $telegramUserId, array $updates): void
    {
        $current = $this->get($telegramUserId) ?? [];

        $this->set($telegramUserId, array_merge($current, $updates));
    }

    public function clear(int $telegramUserId): void
    {
        Cache::store('array')->forget($this->key($telegramUserId));
    }

    public function has(int $telegramUserId): bool
    {
        return $this->get($telegramUserId) !== null;
    }

    private function key(int $telegramUserId): string
    {
        return "tg:state:{$telegramUserId}";
    }
}
