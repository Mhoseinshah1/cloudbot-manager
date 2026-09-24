<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Subscriptions\MonthlyLifecycleService;
use Illuminate\Console\Command;

/**
 * Moves monthly subscriptions through the end of their life.
 *
 * Warns, opens grace, asks for a power-off, and asks for a deletion once grace
 * has closed. It charges nobody: renewal is a customer's decision, and a sweep
 * that quietly debited a wallet at expiry would be spending money nobody agreed
 * to spend.
 *
 * Bounded per run, and every transition is its own short transaction — nothing
 * here holds a lock across a batch, and nothing calls a provider or Telegram.
 */
final class ProcessSubscriptionExpiryCommand extends Command
{
    protected $signature = 'subscriptions:process-expiry
        {--batch=200 : How many subscriptions to examine per stage}';

    protected $description = 'Warn, grace and terminate monthly subscriptions that have expired';

    public function handle(MonthlyLifecycleService $lifecycle): int
    {
        $batch = (int) $this->option('batch');
        $report = $lifecycle->sweep($batch > 0 ? $batch : 200);

        $this->line("expiry warnings sent: {$report->warned}");
        $this->line("grace windows opened: {$report->graceEntered}");
        $this->line("terminations requested: {$report->terminationRequested}");
        $this->line("grace held (no termination): {$report->graceHeld}");

        return self::SUCCESS;
    }
}
