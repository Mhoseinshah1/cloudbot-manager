<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Cloud\Exceptions\ProviderException;
use App\Cloud\Sync\ProviderCatalogSyncService;
use Illuminate\Console\Command;

/**
 * Refreshes locations, plans and images from each provider.
 *
 * Reads happen before any transaction, and a provider that cannot be read
 * leaves its catalogue exactly as it was. That is the important behaviour: an
 * outage must never be read as an empty inventory, because the withdrawal rules
 * would then mark every location unavailable and close the shop.
 */
final class SyncProvidersCommand extends Command
{
    protected $signature = 'providers:sync
        {--provider= : Sync one provider by code}';

    protected $description = 'Sync provider locations, plans and images into the local catalogue';

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
                $report = $sync->sync($provider);
            } catch (ProviderException $exception) {
                // One provider's failure is reported and does not stop the
                // others, but it does decide the exit code: a sync that
                // silently returned success would hide a stale catalogue.
                $this->error("{$provider->code}: {$exception->getMessage()}");
                $failed = true;

                continue;
            }

            $this->line("Provider {$report->providerCode}:");
            $this->line("  locations: {$report->locations} synced, {$report->locationsWithdrawn} withdrawn");
            $this->line("  plans: {$report->plans} synced");
            $this->line("  images: {$report->images} synced, {$report->imagesDeprecated} deprecated");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
