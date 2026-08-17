<?php

namespace App\Services;

use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPricingData;
use App\Models\Provider;
use App\Models\ProviderCatalogSync;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\ProviderPlanPrice;
use Illuminate\Support\Facades\DB;
use Throwable;

class CatalogSyncService
{
    public function __construct(private ProviderManager $manager) {}

    public function sync(Provider $provider): ProviderCatalogSync
    {
        $sync = ProviderCatalogSync::query()->create([
            'provider_id' => $provider->id,
            'status' => ProviderCatalogSync::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $adapter = $this->manager->resolveForSync($provider);

            [$locations, $locationIds] = $this->syncLocations($provider, $adapter);
            [$plans, $planIds] = $this->syncPlans($provider, $adapter);
            $pricingCount = $this->syncPricing($provider, $adapter, $planIds, $locationIds);
            [$images, $imageIds] = $this->syncImages($provider, $adapter);

            $sync->update([
                'status' => ProviderCatalogSync::STATUS_COMPLETED,
                'locations_count' => $locations,
                'plans_count' => $plans,
                'images_count' => $images,
                'pricing_count' => $pricingCount,
                'errors' => null,
                'finished_at' => now(),
            ]);

            return $sync->fresh();
        } catch (Throwable $e) {
            $sync->update([
                'status' => ProviderCatalogSync::STATUS_FAILED,
                'errors' => [['message' => $e->getMessage()]],
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * @return array{0: int, 1: array<string, ProviderLocation>}
     */
    private function syncLocations(Provider $provider, mixed $adapter): array
    {
        $count = 0;
        $byCode = [];

        DB::transaction(function () use ($provider, $adapter, &$count, &$byCode) {
            foreach ($adapter->getLocations() as $location) {
                /** @var ProviderLocationData $location */
                $row = ProviderLocation::query()->updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'provider_location_id' => $location->id,
                    ],
                    [
                        'name' => $location->name,
                        'country_code' => $location->countryCode,
                        'city' => $location->city,
                        'network_zone' => $location->metadata['network_zone'] ?? null,
                        'metadata' => $location->metadata,
                    ]
                );

                $byCode[$location->id] = $row;
                $count++;
            }
        });

        return [$count, $byCode];
    }

    /**
     * @return array{0: int, 1: array<string, ProviderPlan>}
     */
    private function syncPlans(Provider $provider, mixed $adapter): array
    {
        $count = 0;
        $byName = [];

        DB::transaction(function () use ($provider, $adapter, &$count, &$byName) {
            foreach ($adapter->getPlans() as $plan) {
                $row = ProviderPlan::query()->updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'provider_plan_id' => $plan->id,
                    ],
                    [
                        'name' => $plan->name,
                        'description' => $plan->description,
                        'vcpu' => $plan->vcpu,
                        'ram_mb' => $plan->ramMb,
                        'disk_gb' => $plan->diskGb,
                        'bandwidth_gb' => $plan->bandwidthGb,
                        'price_monthly' => $plan->priceMonthly,
                        'currency' => $plan->currency,
                        'price_hourly' => $plan->priceHourly,
                        'cpu_type' => $plan->metadata['cpu_type'] ?? null,
                        'architecture' => $plan->metadata['architecture'] ?? null,
                        'storage_type' => $plan->metadata['storage_type'] ?? null,
                        'deprecated' => (bool) ($plan->metadata['deprecated'] ?? false) || $plan->metadata['deprecation'] !== null,
                        'metadata' => $plan->metadata,
                    ]
                );

                $byName[$plan->id] = $row;
                $count++;
            }
        });

        return [$count, $byName];
    }

    /**
     * Syncs per-location pricing/availability (GET /pricing) and marks
     * per-location deprecation from the server-type catalog.
     *
     * @param  array<string, ProviderPlan>  $plans
     * @param  array<string, ProviderLocation>  $locations
     */
    private function syncPricing(Provider $provider, mixed $adapter, array $plans, array $locations): int
    {
        $seenIds = [];

        DB::transaction(function () use ($adapter, $plans, $locations, &$seenIds) {
            $rows = $adapter->getPricing();

            /** @var ProviderPricingData $price */
            foreach ($rows as $price) {
                $plan = $plans[$price->serverTypeId] ?? null;
                $location = $locations[$price->locationId] ?? null;

                if ($plan === null || $location === null) {
                    continue;
                }

                $row = ProviderPlanPrice::query()->updateOrCreate(
                    [
                        'provider_plan_id' => $plan->id,
                        'provider_location_id' => $location->id,
                    ],
                    [
                        'price_hourly' => $price->priceHourly,
                        'price_monthly' => $price->priceMonthly,
                        'included_traffic' => $price->includedTraffic,
                        'price_per_tb_traffic' => $price->pricePerTbTraffic,
                        'currency' => $price->currency,
                    ]
                );

                $seenIds[] = $row->id;
            }

            // Merge per-location availability/deprecation from the server-type catalog.
            foreach ($plans as $plan) {
                /** @var array<string, mixed> $metadata */
                $metadata = $plan->metadata;

                /** @var array<int, array<string, mixed>> $availability */
                $availability = $metadata['locations'] ?? [];
                if ($availability === []) {
                    $availability = array_map(
                        fn (array $p): array => ['location' => $p['location'] ?? null],
                        $metadata['prices'] ?? []
                    );
                }

                foreach ($availability as $entry) {
                    $code = $entry['location'] ?? null;
                    $location = $code !== null ? ($locations[$code] ?? null) : null;

                    if ($location === null) {
                        continue;
                    }

                    $deprecation = $entry['deprecation'] ?? null;
                    // A non-empty deprecation entry marks this plan/location as deprecated.
                    $deprecated = $deprecation !== null && $deprecation !== [];

                    $row = ProviderPlanPrice::query()->updateOrCreate(
                        [
                            'provider_plan_id' => $plan->id,
                            'provider_location_id' => $location->id,
                        ],
                        ['deprecated' => $deprecated]
                    );

                    $seenIds[] = $row->id;
                }
            }

            // Remove stale price rows (locations no longer offered by the provider).
            // Note: the column references the local plan primary key, not the
            // provider-facing server-type id.
            ProviderPlanPrice::query()
                ->whereIn('provider_plan_id', collect($plans)->pluck('id'))
                ->whereNotIn('id', array_unique($seenIds))
                ->delete();
        });

        return count($seenIds);
    }

    /**
     * @return array{0: int, 1: array<string, ProviderImage>}
     */
    private function syncImages(Provider $provider, mixed $adapter): array
    {
        $count = 0;
        $byId = [];

        DB::transaction(function () use ($provider, $adapter, &$count, &$byId) {
            foreach ($adapter->getImages() as $image) {
                $row = ProviderImage::query()->updateOrCreate(
                    [
                        'provider_id' => $provider->id,
                        'provider_image_id' => $image->id,
                    ],
                    [
                        'name' => $image->name,
                        'os_family' => $image->osFamily,
                        'os_distro' => $image->osDistro,
                        'version' => $image->version,
                        'architecture' => $image->architecture,
                        'type' => $image->metadata['type'] ?? null,
                        'status' => $image->metadata['status'] ?? null,
                        'deprecated' => $image->metadata['deprecated'] ?? null,
                        'metadata' => $image->metadata,
                    ]
                );

                $byId[$image->id] = $row;
                $count++;
            }
        });

        return [$count, $byId];
    }
}
