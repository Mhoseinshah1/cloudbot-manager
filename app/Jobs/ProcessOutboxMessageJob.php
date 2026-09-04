<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\OutboxMessage;
use App\Outbox\OutboxDispatcher;
use App\Outbox\OutboxRouter;
use App\Support\Queues;
use App\Telegram\Exceptions\TelegramRateLimited;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one outbox intent, on the notifications queue and nowhere else.
 *
 * Its own worker, deliberately. Telegram rate limits, and a notification that
 * has to wait ninety seconds must not be waiting on the queue that a customer
 * pressing a button is using, nor on the one building servers.
 *
 * The payload is a row id. Everything else is re-read from PostgreSQL, which is
 * where the truth is anyway — and where it will still be correct if this job
 * sits in a queue for an hour.
 *
 * Running twice is expected and safe. The row is re-read under a lock, an
 * already-processed message is a no-op, and each delivery carries a
 * deduplication key so a duplicated send resolves to one notification record.
 * What none of that provides is exactly-once: a worker that dies after Telegram
 * accepted a message but before the row is marked will send it again. That is
 * unavoidable — no database flag commits atomically with an external send — and
 * for these messages it is a repeated notification, not a repeated charge.
 */
final class ProcessOutboxMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * The durable attempt counter on the row is the real bound; this is just
     * how many times one delivery may be retried before the sweeper decides.
     */
    public int $tries = 3;

    public function __construct(public readonly int $outboxMessageId)
    {
        $this->onQueue(Queues::Notifications->value);
    }

    public function handle(
        OutboxRouter $router,
        OutboxDispatcher $dispatcher,
        CacheFactory $cache,
    ): void {
        $lock = $this->lockFor($cache);

        if (! $lock instanceof Lock) {
            // The configured store cannot coordinate. Handing the message back
            // is safer than delivering it with nothing to stop a second worker
            // doing the same; the sweeper will offer it again.
            Log::warning('outbox.lock_unavailable', ['outbox_message_id' => $this->outboxMessageId]);
            $this->release(5);

            return;
        }

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $this->deliver($router, $dispatcher);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Expired, or Redis went away. The TTL is the backstop, and a
                // failed release must not mask what the delivery did.
            }
        }
    }

    /** The queue this job must run on, for tests and topology checks. */
    public static function queueName(): string
    {
        return Queues::Notifications->value;
    }

    private function deliver(OutboxRouter $router, OutboxDispatcher $dispatcher): void
    {
        // Read holding the lock, so the decision is about what the last holder
        // left behind rather than what was true before them.
        $message = OutboxMessage::query()->whereKey($this->outboxMessageId)->first();

        if (! $message instanceof OutboxMessage || $message->isProcessed()) {
            return;
        }

        // Spent before the work. A message that crashes its worker has still
        // used an attempt, which is what stops it being retried forever.
        if (! $dispatcher->reserveAttempt($message)) {
            return;
        }

        try {
            $finished = $router->route($message);
        } catch (TelegramRateLimited $limited) {
            // Telegram asked us to wait. Released for exactly that long, and
            // emphatically not marked processed: the message has not been sent.
            $this->release($limited->retryAfterSeconds);

            return;
        }

        if ($finished) {
            $dispatcher->markProcessed($message);
        }
    }

    /**
     * The lock, from the store that can actually make one.
     *
     * Taken from the underlying store rather than the cache repository, which
     * has no `lock()` of its own.
     */
    private function lockFor(CacheFactory $cache): ?Lock
    {
        $repository = $cache->store('locks');
        $store = $repository instanceof CacheRepository ? $repository->getStore() : null;

        return $store instanceof LockProvider
            ? $store->lock('outbox:message:'.$this->outboxMessageId, 60)
            : null;
    }
}
