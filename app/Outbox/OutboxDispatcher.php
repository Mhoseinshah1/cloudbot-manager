<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Jobs\ProcessOutboxMessageJob;
use App\Models\OutboxMessage;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * Puts undelivered intents onto the notifications queue.
 *
 * A sweep rather than a hook, because a hook is exactly what fails. Dispatching
 * a job right after the transaction commits works until the process dies in
 * between, and then a customer's server is built and nobody ever tells them —
 * or worse, an order sits paid with no provisioning ever requested. This
 * repairs that by looking at what is actually still unprocessed.
 *
 * Bounded on both sides. A batch limit stops one sweep pulling a backlog into
 * memory; an attempt limit stops a message that Telegram will never accept
 * being retried until the end of time. A message past its attempts stays in the
 * table, unprocessed and visible, which is the right failure mode: an intent
 * nobody delivered can still be delivered, and one that was quietly discarded
 * cannot.
 */
final readonly class OutboxDispatcher
{
    public function __construct(private Config $config) {}

    /**
     * Queue delivery for everything unprocessed and due.
     *
     * @return int How many were queued.
     */
    public function sweep(): int
    {
        $queued = 0;

        foreach ($this->due() as $message) {
            ProcessOutboxMessageJob::dispatch((int) $message->getKey());
            $queued++;
        }

        return $queued;
    }

    /**
     * Unprocessed, due, oldest first, bounded.
     *
     * @return \Illuminate\Support\Collection<int, OutboxMessage>
     */
    public function due(): \Illuminate\Support\Collection
    {
        return OutboxMessage::query()
            ->whereNull('processed_at')
            ->where('available_at', '<=', now())
            ->where('attempts', '<', $this->maximumAttempts())
            ->orderBy('id')
            ->limit($this->batchSize())
            ->get();
    }

    /**
     * Claim one attempt at this message, durably.
     *
     * Before the work rather than after it, so a worker that dies mid-delivery
     * has still spent an attempt. Counting afterwards is how a message that
     * crashes the worker gets retried forever.
     *
     * @return bool Whether this worker may proceed.
     */
    public function reserveAttempt(OutboxMessage $message): bool
    {
        return OutboxMessage::query()
            ->whereKey($message->getKey())
            ->whereNull('processed_at')
            ->where('attempts', '<', $this->maximumAttempts())
            ->update(['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]) === 1;
    }

    /**
     * Mark one message delivered, once.
     *
     * Compare-and-set on `processed_at`, so two workers that both delivered
     * cannot both believe they were the one that did.
     */
    public function markProcessed(OutboxMessage $message): bool
    {
        return OutboxMessage::query()
            ->whereKey($message->getKey())
            ->whereNull('processed_at')
            ->update(['processed_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function maximumAttempts(): int
    {
        return max(1, (int) $this->config->get('cloudbot.outbox.max_attempts', 5));
    }

    private function batchSize(): int
    {
        return max(1, (int) $this->config->get('cloudbot.outbox.dispatch_batch', 100));
    }
}
