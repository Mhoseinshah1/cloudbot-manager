<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TelegramAccount;
use App\Models\TelegramUpdate;
use App\Support\Queues;
use App\Telegram\Data\NormalizedUpdate;
use App\Telegram\Exceptions\TelegramApiException;
use App\Telegram\Exceptions\TelegramForbidden;
use App\Telegram\Exceptions\TelegramRateLimited;
use App\Telegram\TelegramAccountService;
use App\Telegram\TelegramUpdateProcessor;
use App\Telegram\TelegramUpdateRecorder;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handles one Telegram update, away from the web request.
 *
 * On the `telegram` queue and nowhere else. That queue has its own worker with
 * a short timeout, because the thing it protects is responsiveness: a customer
 * pressing a button must not wait behind somebody's server being built, and a
 * provider call that blocks for minutes must never be drained by this worker.
 *
 * The payload is a row id. Not the update, not the payload Telegram sent, and
 * certainly not a token — a job payload is serialized into Redis, read by
 * anything that can reach it, and printed whole in a failed-job record. The
 * safe normalized facts are re-read from PostgreSQL, which is where they were
 * put precisely so they would not have to travel.
 *
 * Running twice for one update is safe and expected: Telegram retries, and a
 * dispatch can be duplicated. The row's status is the guard, and the work
 * itself is a greeting rather than anything that spends money.
 *
 * That guard is not exactly-once, and the Redis lock does not make it so. No
 * database flag can be committed atomically with an external send: a worker
 * that dies after Telegram accepted a message but before the row is marked
 * will send it again on retry. What the lock and the re-read below remove is
 * the avoidable case — two healthy workers duplicating an effect purely
 * because one of them decided from a row it read before the other finished.
 * The unavoidable case remains, and for this phase it is limited to
 * presentation: a repeated greeting. Anything that moves money or builds a
 * server must carry its own durable idempotency tied to the business intent
 * rather than relying on this.
 */
final class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Queueable;

    /**
     * Short. Interactive work that has failed three times is not going to
     * succeed on the fourth while a customer waits.
     */
    public int $tries = 3;

    public function __construct(public readonly int $telegramUpdateId)
    {
        $this->onQueue(Queues::Telegram->value);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(
        TelegramUpdateRecorder $recorder,
        TelegramUpdateProcessor $processor,
        TelegramAccountService $accounts,
        CacheFactory $cache,
    ): void {
        // Nothing is read before the lock, deliberately. The lock key is built
        // from the payload alone, and a row read beforehand describes the world
        // as it was before whoever currently holds the lock finished with it —
        // so acting on one is acting on an answer that has already expired.
        //
        // Coordination only, and short-lived. Two workers handling one update
        // would send a customer the menu twice; neither the lock nor its
        // absence is what makes the system correct, which is why a crashed
        // holder simply loses the lock rather than leaving the update in a
        // state nothing will pick up again.
        $lock = $this->lockFor($cache);

        if (! $lock instanceof Lock) {
            // The configured store cannot coordinate at all. Going ahead
            // anyway would be handling the update with the one thing that
            // stops a customer being messaged twice simply missing, so the
            // update goes back to the queue untouched instead. It is still
            // pending, so a retry starts exactly where this attempt did.
            Log::warning('telegram.lock_unavailable', [
                'telegram_update_id' => $this->telegramUpdateId,
            ]);

            $this->release(5);

            return;
        }

        if (! $lock->get()) {
            // Somebody else is already handling this one.
            $this->release(5);

            return;
        }

        try {
            // Read now, holding the lock, so what this decides on is what the
            // last holder left behind rather than what was true before them.
            $update = TelegramUpdate::query()->whereKey($this->telegramUpdateId)->first();

            if (! $update instanceof TelegramUpdate) {
                // Nothing to do, and nothing a retry could fix.
                return;
            }

            if (! $update->isPending()) {
                // Somebody finished it while this worker was waiting. Nothing
                // is sent and nothing is written: losing the markProcessed
                // compare-and-set afterwards would be too late, because it
                // cannot recall a message Telegram has already delivered.
                return;
            }

            $this->run($update, $recorder, $processor, $accounts);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Already expired, or Redis went away. The TTL is the backstop.
                // Never allowed to mask what the processing itself did.
            }
        }
    }

    /**
     * The lock for this update, from the store that can actually make one.
     *
     * Taken from the underlying store rather than the cache repository, which
     * has no `lock()` method of its own — calls reach the store through magic
     * forwarding, so asking the repository directly is a runtime hope rather
     * than a checked call.
     *
     * Null when the configured store cannot provide locks, which the caller
     * treats as a reason to retry rather than to proceed: coordination that
     * cannot be obtained is not the same as coordination that is not needed.
     * The permanent guarantee remains the unique update_id and the row's
     * status; this lock only keeps two workers off one update at once.
     */
    private function lockFor(CacheFactory $cache): ?Lock
    {
        $repository = $cache->store('locks');
        $store = $repository instanceof CacheRepository ? $repository->getStore() : null;

        return $store instanceof LockProvider
            ? $store->lock($this->lockKey(), 30)
            : null;
    }

    private function run(
        TelegramUpdate $update,
        TelegramUpdateRecorder $recorder,
        TelegramUpdateProcessor $processor,
        TelegramAccountService $accounts,
    ): void {
        try {
            $processor->process($update, self::rebuild($update));
        } catch (TelegramRateLimited $limited) {
            // Telegram asked us to wait. Released for exactly that long — not
            // retried immediately, and nothing sleeps while holding a worker.
            // The update stays pending, because it genuinely has not happened.
            $recorder->markFailed($update, 'rate_limited');
            $this->release($limited->retryAfterSeconds);

            return;
        } catch (TelegramForbidden $forbidden) {
            // The customer blocked the bot. Recorded against the account
            // Telegram actually refused, and then finished: retrying cannot
            // change their mind, and a job that keeps trying turns one person's
            // choice into an endless failed-job loop.
            $this->markBlocked($update, $accounts);
            $recorder->markProcessed($update);

            return;
        } catch (TelegramApiException $failure) {
            $recorder->markFailed($update, 'telegram_api_error');

            // Identifiers and a category. The exception's own message is
            // already scrubbed, and none of it is copied here.
            Log::warning('telegram.update_failed', [
                'telegram_update_id' => $update->getKey(),
                'update_id' => $update->update_id,
                'error_code' => $failure->errorCode,
            ]);

            throw $failure;
        }

        $recorder->markProcessed($update);
    }

    /**
     * Rebuild the safe facts from the row.
     *
     * The row holds exactly what the boundary decided to keep, so this is a
     * straight read rather than a second parse of anything untrusted.
     */
    public static function rebuild(TelegramUpdate $update): NormalizedUpdate
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $update->metadata ?? [];

        return new NormalizedUpdate(
            updateId: $update->update_id,
            type: $update->type,
            chatType: $update->chat_type,
            telegramUserId: $update->telegram_user_id,
            telegramChatId: $update->telegram_chat_id,
            messageId: $update->message_id,
            callbackQueryId: $update->callback_query_id,
            action: $update->action,
            profile: [
                'username' => self::stringOrNull($metadata['username'] ?? null),
                'first_name' => self::stringOrNull($metadata['first_name'] ?? null),
                'last_name' => self::stringOrNull($metadata['last_name'] ?? null),
            ],
            isBot: ($metadata['is_bot'] ?? false) === true,
        );
    }

    /** The queue this job must run on, for tests and topology checks. */
    public static function queueName(): string
    {
        return Queues::Telegram->value;
    }

    private function markBlocked(TelegramUpdate $update, TelegramAccountService $accounts): void
    {
        if ($update->telegram_user_id === null) {
            return;
        }

        $account = $accounts->findByTelegramUserId($update->telegram_user_id);

        if ($account instanceof TelegramAccount) {
            $accounts->markBotBlocked($account);
        }
    }

    private function lockKey(): string
    {
        return 'telegram:update:'.$this->telegramUpdateId;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
