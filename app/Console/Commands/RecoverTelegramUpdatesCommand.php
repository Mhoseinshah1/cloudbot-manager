<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Telegram\TelegramUpdateRecovery;
use Illuminate\Console\Command;

/**
 * Queues the work again for Telegram updates whose deliveries were lost.
 *
 * The webhook records the update and then queues it, and nothing makes those
 * atomic. Without this, a lost job leaves a row at `received` that nothing ever
 * looks at again — a customer pressing a button and getting silence.
 *
 * Bounded per run, and it dispatches rather than processes: talking to Telegram
 * belongs on the telegram worker, not in the scheduler container.
 */
final class RecoverTelegramUpdatesCommand extends Command
{
    protected $signature = 'telegram:recover-updates';

    protected $description = 'Redispatch Telegram updates that were never processed';

    public function handle(TelegramUpdateRecovery $recovery): int
    {
        $queued = $recovery->sweep();

        $this->info($queued === 0
            ? 'No abandoned Telegram updates.'
            : "Redispatched {$queued} Telegram update(s).");

        return self::SUCCESS;
    }
}
