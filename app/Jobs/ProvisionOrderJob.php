<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Provisioning\ProvisioningService;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Builds one order's server, on the provisioning queue and nowhere else.
 *
 * The queue choice is a correctness property, not a preference. A provider
 * create can block for minutes; sharing a worker with interactive Telegram
 * traffic would mean every customer pressing a button waits behind somebody
 * else's server being built. The Compose topology gives this queue its own
 * worker with a long timeout and low concurrency for exactly that reason.
 *
 * The payload is an order id. Not the order, not the provider, and certainly
 * not a credential: a job payload is serialized into Redis, read by anything
 * that can reach it, and printed whole in a failed-job record. Everything the
 * work needs is re-read from PostgreSQL, which is where the truth is anyway.
 *
 * Retries are the coordinator's decision, surfaced through its result. This job
 * never re-runs itself because an error "looked transient" — a create that may
 * have succeeded has to reconcile its token first, and that is the coordinator's
 * job to arrange.
 */
final class ProvisionOrderJob implements ShouldQueue
{
    use Queueable;

    /**
     * The specification's retry policy: three attempts, backing off.
     */
    public int $tries = 3;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue(Queues::Provisioning->value);
    }

    /**
     * Approximately 30s, 120s, then 600s, from config rather than inline.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var array<array-key, mixed> $backoff */
        $backoff = config('cloudbot.provisioning.backoff_seconds', [30, 120, 600]);

        return array_values(array_map(static fn (mixed $seconds): int => (int) $seconds, $backoff));
    }

    public function handle(ProvisioningService $provisioning): void
    {
        $order = Order::query()->whereKey($this->orderId)->first();

        if (! $order instanceof Order) {
            // The order is gone. Orders are never deleted, so this is a job for
            // an id that never existed; nothing to do and nothing to retry.
            return;
        }

        $result = $provisioning->provision($order);

        // Identifiers and a state name. No provider response, no exception, and
        // nothing a customer would mind being in a log file.
        Log::info('provisioning.run', [
            'order_id' => $this->orderId,
            'state' => $result->state,
            'outcome' => $result->outcome?->value,
        ]);

        if ($result->shouldRetry()) {
            // Hand the decision back to the queue's own backoff rather than
            // looping here. The next run reconciles the token before it
            // considers creating anything.
            $this->release($this->backoffFor($this->attempts()));
        }
    }

    /**
     * The queue name this job must run on, for tests and topology checks.
     *
     * Deliberately not called `queue()`. Laravel treats a `queue()` method on a
     * job as a custom queueing hook and calls it instead of pushing the job —
     * so a helper by that name silently swallows every dispatch, which is a
     * failure with no error and no job.
     */
    public static function queueName(): string
    {
        return Queues::Provisioning->value;
    }

    private function backoffFor(int $attempt): int
    {
        $backoff = $this->backoff();
        $index = max(0, min($attempt - 1, count($backoff) - 1));

        return $backoff[$index] ?? 600;
    }
}
