<?php

namespace App\Services\Telegram;

use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Maps Telegram user IDs to local User records.
 *
 * Telegram user ID is the authoritative identity. Username is metadata
 * only and never used for lookups or authorization.
 */
class TelegramUserService
{
    /**
     * Find or create a local user linked to a Telegram account.
     *
     * The Telegram numeric user ID is the unique key. On first contact
     * a local user is created with the Telegram first name; subsequent
     * visits update profile fields.
     */
    public function resolveOrCreate(int $telegramUserId, ?string $firstName = null, ?string $lastName = null, ?string $username = null, ?int $chatId = null): User
    {
        $existing = TelegramAccount::query()
            ->where('telegram_user_id', $telegramUserId)
            ->with('user')
            ->first();

        if ($existing !== null) {
            $existing->update([
                'first_name' => $firstName ?? $existing->first_name,
                'last_name' => $lastName ?? $existing->last_name,
                'username' => $username ?? $existing->username,
                'telegram_chat_id' => $chatId ?? $existing->telegram_chat_id,
            ]);

            /** @var User|null $linkedUser */
            $linkedUser = $existing->user;

            return $linkedUser ?? throw new \RuntimeException('Telegram account has no linked user');
        }

        $user = User::query()->create([
            'name' => trim(($firstName ?? '').' '.($lastName ?? '')) ?: 'Telegram User',
            'email' => 'tg_'.$telegramUserId.'@telegram.local',
            'password' => bcrypt(Str::random(32)),
        ]);

        TelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $chatId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'username' => $username,
        ]);

        return $user;
    }

    /**
     * Find a local user by Telegram user ID.
     */
    public function findByTelegramId(int $telegramUserId): ?User
    {
        $account = TelegramAccount::query()
            ->where('telegram_user_id', $telegramUserId)
            ->with('user')
            ->first();

        $user = $account?->user;

        return $user instanceof User ? $user : null;
    }

    /**
     * Get the chat_id for a local user (for sending proactive messages).
     */
    public function getChatId(User $user): ?int
    {
        $account = $user->telegramAccount;

        return $account !== null ? (int) $account->telegram_chat_id : null;
    }
}
