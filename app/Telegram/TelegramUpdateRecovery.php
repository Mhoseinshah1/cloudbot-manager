<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Enums\TelegramUpdateStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Collection;

/**
 * Finds received updates whose queue deliveries are all gone.
 *
 * The webhook writes the row and then queues the work, and those two cannot be
 * one atomic step. Redis can lose the job, every delivery can be exhausted
 * against a lock that was never free, or the worker can die between the two — and
 * the row then sits at `received` forever, because nothing else looks at it. To
 * the customer that is a button that did nothing.
 *
 * Redispatch is safe by construction rather than by care taken here: the update
 * row is unique on `update_id`, the job re-reads the row under the update lock,
 * the status compare-and-set admits one finisher, and the durable availability
 * barrier is honoured before anything reaches Telegram.
 *
 * Bounded, oldest first, and nothing is sent from the scheduler itself: this
 * only queues work onto the telegram worker, which is the process built to wait
 * on Telegram.
 */
final readonly class TelegramUpdateRecovery
{
    public function __construct(private Config $config) {}

    /**
     * Queue the work again for updates nobody finished.
     *
     * @return int How many were redispatched.
     */
    public function sweep(): int
    {
        $queued = 0;

        foreach ($this->abandoned() as $update) {
            ProcessTelegramUpdateJob::dispatch((int) $update->getKey());
            $queued++;
        }

        return $queued;
    }

    /**
     * Unprocessed updates old enough that their deliveries are gone.
     *
     * A row seconds old is probably in a worker's hands right now, so the delay
     * is what keeps this from racing the ordinary path. The availability
     * barrier is applied here as well as in the job: queuing work that is only
     * going to release itself wastes a delivery and a worker.
     *
     * @return Collection<int, TelegramUpdate>
     */
    public function abandoned(): Collection
    {
        return TelegramUpdate::query()
            ->where('status', '!=', TelegramUpdateStatus::Processed->value)
            ->where('received_at', '<=', CarbonImmutable::now()->subSeconds($this->delaySeconds()))
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($this->batchSize())
            ->get();
    }

    private function delaySeconds(): int
    {
        return max(1, (int) $this->config->get('cloudbot.telegram.recover_after_seconds', 300));
    }

    private function batchSize(): int
    {
        return max(1, (int) $this->config->get('cloudbot.telegram.recover_batch', 100));
    }
}
