<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationLog;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Support\Secrets\SecretScrubber;
use App\Telegram\Exceptions\TelegramForbidden;
use App\Telegram\Exceptions\TelegramRateLimited;
use App\Telegram\TelegramAccountService;
use App\Telegram\TelegramApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\QueryException;

/**
 * Sends what the outbox decided somebody should be told.
 *
 * Never called from inside a transaction. A message sent before a commit is a
 * message that may describe something that then rolled back, and a customer
 * told their server is ready cannot be untold.
 *
 * Every attempt is recorded, including the ones that did not work. "I was never
 * told my server was ready" is a support question only this history can answer,
 * and a log that recorded successes only could never answer it.
 *
 * Two refusals are handled and they are not the same. A customer blocking the
 * bot is their decision, permanent until they change it, and retrying it
 * forever would be arguing with somebody who left. Being rate limited is
 * temporary, and the job is released for exactly as long as Telegram asked
 * rather than marked delivered.
 */
final readonly class NotificationService
{
    public function __construct(
        private TelegramApiClient $telegram,
        private TelegramAccountService $accounts,
        private Config $config,
    ) {}

    /**
     * Tell one customer something.
     *
     * @param  array<string, mixed>  $summary  Facts for the support record.
     *
     * @throws TelegramRateLimited So the caller can release rather than falsely
     *                             record a delivery.
     */
    public function toCustomer(
        User $customer,
        string $type,
        string $message,
        array $summary = [],
        ?int $outboxMessageId = null,
        ?string $deduplicationKey = null,
    ): NotificationLog {
        $chatId = $this->chatFor($customer);

        if ($chatId === null) {
            // Nobody to send to. Not a failure to retry: a customer with no
            // private chat has never spoken to the bot.
            return $this->record(
                $customer,
                NotificationChannel::TelegramCustomer,
                $type,
                NotificationStatus::Undeliverable,
                $summary,
                $outboxMessageId,
                $deduplicationKey,
            );
        }

        try {
            $this->telegram->sendMessage($chatId, $message);
        } catch (TelegramForbidden) {
            // They blocked the bot. Recorded against the account Telegram
            // actually refused, and then finished — retrying cannot change
            // somebody's mind.
            $this->markBlocked($customer);

            return $this->record(
                $customer,
                NotificationChannel::TelegramCustomer,
                $type,
                NotificationStatus::Blocked,
                $summary,
                $outboxMessageId,
                $deduplicationKey,
            );
        }

        return $this->record(
            $customer,
            NotificationChannel::TelegramCustomer,
            $type,
            NotificationStatus::Sent,
            $summary,
            $outboxMessageId,
            $deduplicationKey,
        );
    }

    /**
     * Tell whoever operates this installation something.
     *
     * @param  array<string, mixed>  $summary
     *
     * @throws TelegramRateLimited
     */
    public function toAdministrators(
        string $type,
        string $message,
        array $summary = [],
        ?int $outboxMessageId = null,
        ?string $deduplicationKey = null,
    ): NotificationLog {
        $chatId = $this->adminChatId();

        if ($chatId === null) {
            // No destination configured. Recorded rather than thrown, and
            // deliberately not counted as delivered: the intent is answered so
            // it does not retry forever, and the log says plainly that nobody
            // was told.
            return $this->record(
                null,
                NotificationChannel::TelegramAdmin,
                $type,
                NotificationStatus::Undeliverable,
                $summary,
                $outboxMessageId,
                $deduplicationKey,
            );
        }

        try {
            $this->telegram->sendMessage($chatId, $message);
        } catch (TelegramForbidden) {
            // The operator channel refused us. An operational failure, and
            // emphatically not a customer who blocked the bot — there is no
            // TelegramAccount here to mark, and inventing one would attribute
            // an alert channel to a person.
            return $this->record(
                null,
                NotificationChannel::TelegramAdmin,
                $type,
                NotificationStatus::Failed,
                $summary,
                $outboxMessageId,
                $deduplicationKey,
            );
        }

        return $this->record(
            null,
            NotificationChannel::TelegramAdmin,
            $type,
            NotificationStatus::Sent,
            $summary,
            $outboxMessageId,
            $deduplicationKey,
        );
    }

    /** Whether operational alerts have anywhere to go. */
    public function hasAdminDestination(): bool
    {
        return $this->adminChatId() !== null;
    }

    /**
     * The customer's private chat, or null.
     *
     * Private only. A customer's server details must not be posted into a group
     * because the bot once saw them in one.
     */
    private function chatFor(User $customer): ?int
    {
        $account = TelegramAccount::query()
            ->where('user_id', $customer->getKey())
            ->whereNotNull('telegram_chat_id')
            ->orderByDesc('id')
            ->first();

        return $account instanceof TelegramAccount ? $account->telegram_chat_id : null;
    }

    private function markBlocked(User $customer): void
    {
        $account = TelegramAccount::query()
            ->where('user_id', $customer->getKey())
            ->orderByDesc('id')
            ->first();

        if ($account instanceof TelegramAccount) {
            $this->accounts->markBotBlocked($account);
        }
    }

    /**
     * Where operational alerts go, from config.
     *
     * No default, and no secret in code. An installation that has not been told
     * where to send alerts does not have somewhere plausible guessed for it.
     */
    private function adminChatId(): ?int
    {
        $configured = $this->config->get('telegram.admin_chat_id');

        if (is_int($configured)) {
            return $configured;
        }

        if (is_string($configured) && preg_match('/^-?\d+$/', trim($configured)) === 1) {
            return (int) trim($configured);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function record(
        ?User $customer,
        NotificationChannel $channel,
        string $type,
        NotificationStatus $status,
        array $summary,
        ?int $outboxMessageId,
        ?string $deduplicationKey,
    ): NotificationLog {
        $attributes = [
            'user_id' => $customer?->getKey(),
            'outbox_message_id' => $outboxMessageId,
            'channel' => $channel->value,
            'type' => $type,
            'status' => $status->value,
            'deduplication_key' => $deduplicationKey,
            // Scrubbed on the way in. A summary is assembled from facts, but
            // this table is read casually and the cost of one credential
            // reaching it is not worth the convenience of trusting callers.
            'summary' => SecretScrubber::scrub($summary),
            'sent_at' => $status === NotificationStatus::Sent ? CarbonImmutable::now() : null,
        ];

        if ($deduplicationKey === null) {
            return NotificationLog::query()->create($attributes);
        }

        try {
            return NotificationLog::query()->create($attributes);
        } catch (QueryException $exception) {
            $existing = NotificationLog::query()->where('deduplication_key', $deduplicationKey)->first();

            if ($existing instanceof NotificationLog) {
                return $existing;
            }

            throw $exception;
        }
    }
}
