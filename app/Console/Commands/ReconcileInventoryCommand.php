<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Provider;
use App\Provisioning\InventoryReconciler;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compares every provider's inventory with what we believe we sold.
 *
 * Runs daily, and by hand when something looks wrong. It is a financial control:
 * a machine the provider holds that we do not know about is a bill nobody is
 * paying for, and a machine we bill for that the provider does not hold is a
 * customer paying for nothing.
 *
 * Exits non-zero when an inventory could not be read. That distinction matters
 * more than any count here — a provider we could not reach must never be
 * reported as a provider holding nothing.
 */
final class ReconcileInventoryCommand extends Command
{
    protected $signature = 'providers:reconcile-inventory
        {--provider= : Reconcile one provider by code}';

    protected $description = 'Compare provider inventory against local server records and report drift';

    public function handle(InventoryReconciler $reconciler): int
    {
        $code = $this->option('provider');

        $providers = Provider::query()
            // Disabled providers are included on purpose. Disabling stops new
            // spending; it does not stop the bills for what is already running.
            ->when(
                $code !== null && $code !== '',
                static fn (Builder $query): Builder => $query->where('code', $code),
            )
            ->orderBy('id')
            ->get();

        if ($providers->isEmpty()) {
            $this->error($code === null || $code === '' ? 'No providers are configured.' : "No provider with code {$code}.");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($providers as $provider) {
            $report = $reconciler->reconcile($provider);

            if (! $report->succeeded()) {
                $failed = true;
                $this->error("{$provider->code}: {$report->failure}");

                continue;
            }

            $this->line(sprintf(
                '%s: %d checked, %d drift corrected, %d missing remotely, %d orphaned remotely',
                $provider->code,
                $report->localChecked,
                $report->drifted,
                $report->missing,
                $report->orphans,
            ));

            if ($report->missing > 0 || $report->orphans > 0) {
                // Surfaced, never acted on destructively. Linking or removing
                // an orphan is an operator's decision.
                $this->warn("{$provider->code}: discrepancies need an operator's attention.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
