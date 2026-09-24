<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cloud\Exceptions\ProviderException;
use App\Cloud\Sync\ProviderCatalogSyncService;
use Illuminate\Console\Command;

/**
 * Refreshes the per-location provider cost behind products already on sale.
 *
 * Cost only. The selling price is an operator's decision and is never computed
 * from a provider figure here, and no row is created: a price the provider
 * publishes is not a decision to sell there.
 *
 * A pair the provider has stopped pricing has its cost cleared, which makes
 * `PricingService` refuse a new sale rather than quote against a number nobody
 * can confirm. That only ever follows a complete successful fetch — a failed
 * request leaves every cost untouched.
 */
final class SyncProviderPricingCommand extends Command
{
    protected $signature = 'providers:sync-pricing
        {--provider= : Sync pricing for one provider by code}';

    protected $description = 'Refresh per-location provider costs from provider pricing';

    public function handle(ProviderCatalogSyncService $sync): int
    {
        $code = $this->option('provider');
        $code = is_string($code) && $code !== '' ? $code : null;

        $providers = $sync->selectable($code);

        if ($providers === []) {
            $this->error($code === null
                ? 'No providers are configured with a registered implementation.'
                : "No provider with code {$code} has a registered implementation.");

            return self::FAILURE;
        }

        $failed = false;

        foreach ($providers as $provider) {
            try {
                $report = $sync->syncPricing($provider);
            } catch (ProviderException $exception) {
                $this->error("{$provider->code}: {$exception->getMessage()}");
                $failed = true;

                continue;
            }

            $this->line("Provider {$report->providerCode}:");
            $this->line("  pairs: {$report->pairsReceived} received");
            $this->line("  location costs updated: {$report->costsUpdated}");
            $this->line("  missing costs cleared: {$report->costsCleared}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
