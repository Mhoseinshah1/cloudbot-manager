<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Enums\UserCreatedVia;
use App\Enums\UserStatus;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Telegram\Data\NormalizedUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Who a Telegram update is from, and creating that person if this is their
 * first message.
 *
 * Identity here is `telegram_user_id` and nothing else. A username is a display
 * name: people change theirs, and a released one can be taken by somebody else
 * a week later. Looking an account up by username would eventually hand one
 * customer's servers and wallet to another, so the numeric id is the only thing
 * ever matched on, and the username is stored purely to render.
 *
 * First contact creates a User and a TelegramAccount together, in one
 * transaction. Half of that surviving is a customer record nobody can reach, or
 * an identity pointing at nothing.
 *
 * Two things this deliberately will not do. It never revives a suspended or
 * banned account — `/start` is not an appeal — and it never lets a group or
 * channel change where a customer's private messages go, because that would
 * redirect their invoices and credentials into a room full of strangers.
 */
final readonly class TelegramAccountService
{
    public function __construct(private Config $config) {}

    /**
     * Find or create the account behind this update.
     *
     * Returns null when the update carries no usable identity — a channel post,
     * or another bot talking. Neither is a customer.
     */
    public function identify(NormalizedUpdate $normalized): ?TelegramAccount
    {
        if (! $normalized->hasIdentity()) {
            return null;
        }

        $telegramUserId = (int) $normalized->telegramUserId;
        $existing = $this->findByTelegramUserId($telegramUserId);

        if ($existing instanceof TelegramAccount) {
            return $this->refresh($existing, $normalized);
        }

        return $this->create($telegramUserId, $normalized);
    }

    public function findByTelegramUserId(int $telegramUserId): ?TelegramAccount
    {
        $account = TelegramAccount::query()
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        return $account instanceof TelegramAccount ? $account : null;
    }

    /**
     * Create the customer and their Telegram identity, together.
     *
     * Concurrency here is ordinary rather than exotic: a customer who taps
     * `/start` twice, or whose first update Telegram redelivered, produces two
     * updates with different ids from the same person at the same moment. The
     * unique index on `telegram_user_id` decides, and the loser rolls back the
     * User it had begun to create rather than leaving an unreachable orphan.
     */
    private function create(int $telegramUserId, NormalizedUpdate $normalized): TelegramAccount
    {
        try {
            return DB::transaction(function () use ($telegramUserId, $normalized): TelegramAccount {
                $user = User::query()->create([
                    'name' => $this->displayName($normalized),
                    // Both null, legitimately. An account that arrived through
                    // Telegram has no email and no password, and inventing
                    // either would be inventing a credential.
                    'email' => null,
                    'password' => null,
                    'status' => UserStatus::Active->value,
                    'created_via' => UserCreatedVia::Telegram->value,
                    'locale' => (string) $this->config->get('cloudbot.defaults.locale', 'fa'),
                    'timezone' => (string) $this->config->get('cloudbot.defaults.timezone', 'Asia/Tehran'),
                ]);

                return TelegramAccount::query()->create([
                    'user_id' => $user->getKey(),
                    'telegram_user_id' => $telegramUserId,
                    // Only a private chat may become the destination for this
                    // customer's messages.
                    'telegram_chat_id' => $normalized->isPrivate()
                        ? $normalized->telegramChatId
                        : $telegramUserId,
                    'username' => $normalized->profile['username'] ?? null,
                    'first_name' => $normalized->profile['first_name'] ?? null,
                    'last_name' => $normalized->profile['last_name'] ?? null,
                    'last_seen_at' => CarbonImmutable::now(),
                ]);
            });
        } catch (QueryException $exception) {
            // Somebody else got there first. The transaction above rolled back,
            // so no orphan User remains.
            $winner = $this->findByTelegramUserId($telegramUserId);

            if ($winner instanceof TelegramAccount) {
                return $this->refresh($winner, $normalized);
            }

            throw $exception;
        }
    }

    /**
     * Update the safe, cosmetic parts of a known account.
     *
     * Three whitelisted fields, the last-seen stamp, and — for a private
     * message — where to reply. Nothing about status, wallet, roles, orders or
     * accepted terms is touched: `/start` is a greeting, not an administrative
     * action.
     */
    private function refresh(TelegramAccount $account, NormalizedUpdate $normalized): TelegramAccount
    {
        $changes = [
            'username' => $normalized->profile['username'] ?? null,
            'first_name' => $normalized->profile['first_name'] ?? null,
            'last_name' => $normalized->profile['last_name'] ?? null,
            'last_seen_at' => CarbonImmutable::now(),
        ];

        if ($normalized->isPrivate() && $normalized->telegramChatId !== null) {
            // A group message must never redirect a customer's private mail.
            $changes['telegram_chat_id'] = $normalized->telegramChatId;

            if ($account->bot_blocked_at !== null) {
                // Telegram just delivered a private message from this identity,
                // which is proof the bot is reachable again. Clearing it here
                // rather than waiting for a send means the next notification is
                // actually attempted; if it turns out they blocked us again,
                // the 403 marks it once more.
                $changes['bot_blocked_at'] = null;
            }
        }

        $account->forceFill($changes)->save();

        return $account;
    }

    /**
     * Mark that Telegram refused to deliver to this account.
     *
     * Applied to the account the refusal names, resolved by identity. A
     * username-based lookup could mark the wrong customer, who would then
     * silently stop receiving anything.
     */
    public function markBotBlocked(TelegramAccount $account): void
    {
        $account->forceFill(['bot_blocked_at' => CarbonImmutable::now()])->save();
    }

    /**
     * A name to show, assembled from what Telegram offered.
     *
     * Falls back to the numeric identity rather than to an empty string,
     * because a customer with no display name at all still needs to be findable
     * by a human reading an admin screen.
     */
    private function displayName(NormalizedUpdate $normalized): string
    {
        $parts = array_filter([
            $normalized->profile['first_name'] ?? null,
            $normalized->profile['last_name'] ?? null,
        ], static fn (mixed $part): bool => is_string($part) && trim($part) !== '');

        $name = trim(implode(' ', array_map(static fn (mixed $part): string => (string) $part, $parts)));

        return $name !== '' ? mb_substr($name, 0, 120) : 'Telegram '.$normalized->telegramUserId;
    }
}
