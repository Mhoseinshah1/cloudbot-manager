<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Models\ProviderCatalogSync;
use App\Services\CatalogSyncService;
use Illuminate\Console\Command;

class ProviderSyncCommand extends Command
{
    protected $signature = 'provider:sync {provider? : Provider code, e.g. hetzner}';

    protected $description = 'Sync the provider catalog (locations, plans, pricing, images) from the provider API';

    public function handle(CatalogSyncService $sync): int
    {
        $code = $this->argument('provider') ?? 'hetzner';

        $provider = Provider::query()->where('code', $code)->first();

        if ($provider === null) {
            $this->error("Provider [{$code}] does not exist. Seed it first (php artisan db:seed).");

            return self::FAILURE;
        }

        $this->info("Syncing catalog for provider [{$provider->name}] ({$provider->code})…");

        $record = $sync->sync($provider);

        if ($record->status === ProviderCatalogSync::STATUS_FAILED) {
            /** @var array<int, array{message?: string}> $errors */
            $errors = $record->errors ?? [];
            $this->error('Catalog sync failed: '.($errors[0]['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->table(
            ['Locations', 'Plans', 'Pricing rows', 'Images'],
            [[$record->locations_count, $record->plans_count, $record->pricing_count, $record->images_count]]
        );

        $this->info('Sync completed in '.now()->parse($record->finished_at)->diffForHumans(now()->parse($record->started_at)).'.');

        return self::SUCCESS;
    }
}
