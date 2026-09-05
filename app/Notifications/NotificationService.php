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
 *
 * Nothing is sent without first asking whether it already was. A delivery
 * carrying a durable key looks for a terminal record of itself before it opens
 * a connection, because the alternative is a real duplicate: Telegram accepts
 * the message, the log row commits, the process dies before the outbox is
 * marked, the sweep offers the intent again — and a unique index discovered
 * only *after* the second send tells the customer nothing useful, having
 * already messaged them twice.
 *
 * This is not exactly-once and does not claim to be. A worker that dies after
 * Telegram accepted a message and before any local record commits leaves no
 * evidence at all, and the retry sends again. That window is unavoidable: no
 * database write commits atomically with a network send. What is removed here
 * is the avoidable case, where durable evidence of the completed delivery
 * already exists and was simply never consulted.
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
    ): DeliveryOutcome {
        // Asked before anything is opened. A terminal record means this exact
        // delivery already reached its conclusion, and repeating it would put a
        // second copy in front of a person.
        $already = $this->terminalDelivery($deduplicationKey);

        if ($already instanceof NotificationLog) {
            return DeliveryOutcome::settled($already);
        }

        $account = $this->accountFor($customer);

        if ($account instanceof TelegramAccount && $account->hasBlockedBot()) {
            // They blocked the bot, and Phase 8 recorded it. Sending anyway
            // would be arguing with somebody who left — and Telegram would
            // refuse it, which is a request made only to be told what we
            // already know.
            return DeliveryOutcome::settled($this->record(
                $customer,
                NotificationChannel::TelegramCustomer,
                $type,
                NotificationStatus::Blocked,
                $summary,
                $outboxMessageId,
                $deduplicationKey,
            ));
        }

        $chatId = $account instanceof TelegramAccount ? $account->telegram_chat_id : null;

        if ($chatId === null) {
            // Nobody to send to. Not a failure to retry: a customer with no
            // private chat has never spoken to the bot.
            return DeliveryOutcome::settled($this->record(
                $customer,
                NotificationChannel::TelegramCustomer,
                $type,
                NotificationStatus::Undeliverable,
                $summary,
                $outboxMessageId,
                $deduplicationKey,
            ));
        }

        try {
            $this->telegram->sendMessage($chatId, $message);
        } catch (TelegramForbidden) {
            // They blocked the bot. Recorded against the account Telegram
            // actually refused, and then finished — retrying cannot change
            // somebody's mind.
            $this->markBlocked($customer);

            return DeliveryOutcome::settled($this->record(
                $customer,
                NotificationChannel::TelegramCustomer,
                $type,
                NotificationStatus::Blocked,
                $summary,
                $outboxMessageId,
                $deduplicationKey,
            ));
        }

        return DeliveryOutcome::settled($this->record(
            $customer,
            NotificationChannel::TelegramCustomer,
            $type,
            NotificationStatus::Sent,
            $summary,
            $outboxMessageId,
            $deduplicationKey,
        ));
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
    ): DeliveryOutcome {
        $already = $this->terminalDelivery($deduplicationKey);

        if ($already instanceof NotificationLog) {
            return DeliveryOutcome::settled($already);
        }

        $chatId = $this->adminChatId();

        if ($chatId === null) {
            // No destination configured — which is a gap in configuration, not
            // a failed delivery. Recorded honestly, and deliberately *not*
            // finished: an operational alert about failed provisioning is
            // exactly the thing that must survive until somebody can read it,
            // and marking it done would discard it. Configuring the channel an
            // hour later has to deliver the alert that was already waiting.
            return DeliveryOutcome::deferred(
                $this->record(
                    null,
                    NotificationChannel::TelegramAdmin,
                    $type,
                    NotificationStatus::Undeliverable,
                    $summary,
                    $outboxMessageId,
                    // No key: this attempt must not occupy the intent's
                    // successful-delivery slot, and several of them may
                    // legitimately accumulate while nobody has configured
                    // anywhere to send them.
                    null,
                ),
                $this->deferSeconds(),
            );
        }

        try {
            $this->telegram->sendMessage($chatId, $message);
        } catch (TelegramForbidden) {
            // The operator channel refused us. An operational failure, and
            // emphatically not a customer who blocked the bot — there is no
            // TelegramAccount here to mark, and inventing one would attribute
            // an alert channel to a person.
            //
            // So it is not settled either. A customer's 403 is a decision they
            // made and retrying it forever is arguing with somebody who left;
            // an admin channel's 403 is a permission somebody will fix, and the
            // alert waiting behind it — a failed provisioning, an inventory
            // discrepancy — is the exact message that must still arrive when
            // they do. Marking it done here discards it permanently.
            //
            // The attempt stays spent: a request was made and was refused. That
            // is the difference from a missing destination, where nothing left
            // the building and the attempt is handed back. The finite budget
            // and the bounded delay together stop this becoming a hot loop
            // around a channel that will never accept anything.
            return DeliveryOutcome::refused(
                $this->record(
                    null,
                    NotificationChannel::TelegramAdmin,
                    $type,
                    NotificationStatus::Failed,
                    $summary,
                    $outboxMessageId,
                    // The key is kept. A failed row does not occupy the
                    // successful-delivery slot — the unique index covers sent
                    // rows only — so the attempt stays tied to the intent it
                    // belongs to and the later success still records cleanly.
                    $deduplicationKey,
                ),
                $this->deferSeconds(),
            );
        }

        return DeliveryOutcome::settled($this->record(
            null,
            NotificationChannel::TelegramAdmin,
            $type,
            NotificationStatus::Sent,
            $summary,
            $outboxMessageId,
            $deduplicationKey,
        ));
    }

    /** Whether operational alerts have anywhere to go. */
    public function hasAdminDestination(): bool
    {
        return $this->adminChatId() !== null;
    }

    /**
     * A terminal record of this exact delivery, if one already exists.
     *
     * Terminal means the delivery reached a conclusion nothing should reopen:
     * it was sent, or the recipient refused it. `undeliverable` and `failed`
     * are deliberately absent — the first means there was nowhere to send it
     * and the second means it might work next time, and treating either as
     * done would lose a message somebody should have received.
     */
    private function terminalDelivery(?string $deduplicationKey): ?NotificationLog
    {
        if ($deduplicationKey === null) {
            return null;
        }

        $existing = NotificationLog::query()
            ->where('deduplication_key', $deduplicationKey)
            ->whereIn('status', [NotificationStatus::Sent->value, NotificationStatus::Blocked->value])
            ->first();

        return $existing instanceof NotificationLog ? $existing : null;
    }

    /**
     * The customer's Telegram identity, with its private chat.
     *
     * Private only. A customer's server details must not be posted into a group
     * because the bot once saw them in one.
     */
    private function accountFor(User $customer): ?TelegramAccount
    {
        $account = TelegramAccount::query()
            ->where('user_id', $customer->getKey())
            ->whereNotNull('telegram_chat_id')
            ->orderByDesc('id')
            ->first();

        return $account instanceof TelegramAccount ? $account : null;
    }

    /**
     * How long to wait before offering an alert to an unconfigured channel again.
     *
     * Bounded and configurable rather than immediate: retrying a missing
     * destination every minute is a hot loop around a problem only a person can
     * fix, and retrying it never is how the alert is lost.
     */
    private function deferSeconds(): int
    {
        return max(60, (int) $this->config->get('cloudbot.outbox.admin_defer_seconds', 1800));
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
