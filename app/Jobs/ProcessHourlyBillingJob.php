<?php

namespace App\Jobs;

use App\Services\HourlyBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Charges all servers with due hourly usage. Idempotent by design: every
 * interval is guarded by the server_billing_periods unique index.
 */
class ProcessHourlyBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(HourlyBillingService $billing): void
    {
        $billing->processDueServers();
    }
}
