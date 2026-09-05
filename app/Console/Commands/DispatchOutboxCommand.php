<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

/**
 * Queues delivery for intents nobody has delivered yet.
 *
 * Run every minute. This is the repair for the case a post-commit dispatch
 * cannot cover: a process that committed the transaction and then died has left
 * a promise nobody is carrying, and only a sweep over what is actually
 * unprocessed can find it.
 */
final class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch';

    protected $description = 'Queue delivery for outbox intents that have not been processed';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $queued = $dispatcher->sweep();

        $this->info($queued === 0
            ? 'Nothing is waiting to be delivered.'
            : "Queued {$queued} outbox message(s) for delivery.");

        return self::SUCCESS;
    }
}
