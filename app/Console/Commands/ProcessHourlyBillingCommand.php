<?php

namespace App\Console\Commands;

use App\Jobs\ProcessHourlyBillingJob;
use App\Services\HourlyBillingService;
use Illuminate\Console\Command;

class ProcessHourlyBillingCommand extends Command
{
    protected $signature = 'billing:process-hourly
        {--sync : Process due servers synchronously instead of dispatching a queued job}';

    protected $description = 'Charge hourly / hourly_capped VPS usage from customer wallets';

    public function handle(HourlyBillingService $billing): int
    {
        if ($this->option('sync')) {
            $recorded = $billing->processDueServers();
            $this->info("Hourly billing finished: {$recorded} billing period(s) recorded.");

            return self::SUCCESS;
        }

        ProcessHourlyBillingJob::dispatch();
        $this->info('Hourly billing job dispatched.');

        return self::SUCCESS;
    }
}
