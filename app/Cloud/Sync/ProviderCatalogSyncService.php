<?php

declare(strict_types=1);

namespace App\Cloud\Sync;

use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\ProviderManager;
use App\Models\Product;
use App\Models\ProductLocationPrice;
use App\Models\Provider;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use Illuminate\Support\Facades\DB;

/**
 * Brings the local catalogue into line with what a provider currently offers.
 *
 * Provider-agnostic on purpose: it sees only the normalized DTOs, so the same
 * code syncs Hetzner and the simulator, and no endpoint, field name or status
 * string from any provider appears here. Everything provider-shaped lives
 * behind `CloudProviderInterface`.
 *
 * Two rules shape the whole class.
 *
 * **Fetch first, then persist.** Every remote read completes before a
 * transaction opens. A sync that wrote locations, then failed fetching plans,
 * would leave the catalogue describing a world that never existed — and holding
 * PostgreSQL locks across a provider's network timeout would block the sale
 * path behind somebody else's outage.
 *
 * **A failed sync changes nothing.** The withdrawal rules below only ever run
 * after a *complete* successful fetch, because "the provider returned nothing"
 * and "we could not reach the provider" look identical from the database and
 * only one of them means the catalogue is wrong. Treating an outage as an empty
 * inventory would mark every location unavailable and take the shop offline.
 *
 * What an operator decided is never overwritten. `enabled` on a location, plan
 * or image is an operator's switch; sync owns the provider's facts beside it
 * and leaves that column alone.
 */
final readonly class ProviderCatalogSyncService
{
    public function __construct(private ProviderManager $providers) {}

    /**
     * The provider rows this command may sync.
     *
     * Rows come from the database, but a row is only actionable if the trusted
     * registry names an implementation for its code — the database never
     * decides which class runs.
     *
     * @return list<Provider>
     */
    public function selectable(?string $code = null): array
    {
        $query = Provider::query()->orderBy('code');

        if ($code !== null && $code !== '') {
            $query->where('code', $code);
        }

        $rows = [];

        foreach ($query->get() as $provider) {
            if ($this->providers->isRegistered($provider->code)) {
                $rows[] = $provider;
            }
        }

        return $rows;
    }

    /**
     * Refresh locations, plans and images for one provider.
     *
     * @throws ProviderException When any remote read fails. Nothing is written
     *                           in that case.
     */
    public function sync(Provider $provider): CatalogSyncReport
    {
        // Honours the provider kill switch and the registry both.
        $driver = $this->providers->for($provider);

        // All three reads, outside any transaction. A partial catalogue is
        // worse than a stale one, so nothing is persisted until every answer is
        // in hand.
        $locations = $driver->getLocations();
        $plans = $driver->getPlans();
        $images = $driver->getImages();

        $report = new CatalogSyncReport($provider->code);

        DB::transaction(function () use ($provider, $locations, $plans, $images, $report): void {
            $this->applyLocations($provider, $locations, $report);
            $this->applyPlans($provider, $plans, $report);
            $this->applyImages($provider, $images, $report);
        });

        return $report;
    }

    /**
     * Refresh the per-location provider cost behind the products already on sale.
     *
     * @throws ProviderException When the pricing read fails. Nothing is written.
     */
    public function syncPricing(Provider $provider): PricingSyncReport
    {
        $driver = $this->providers->for($provider);

        // The complete authoritative snapshot, fetched before anything is
        // written. Only a complete snapshot may clear a cost.
        $pricing = $driver->getPricing();

        $report = new PricingSyncReport($provider->code);
        $report->pairsReceived = count($pricing);

        DB::transaction(function () use ($provider, $pricing, $report): void {
            $this->applyPricing($provider, $pricing, $report);
        });

        return $report;
    }

    /**
     * @param  list<ProviderLocationData>  $locations
     */
    private function applyLocations(Provider $provider, array $locations, CatalogSyncReport $report): void
    {
        $seen = [];

        foreach ($locations as $location) {
            $seen[] = $location->providerLocationId;

            $row = ProviderLocation::query()->firstOrNew([
                'provider_id' => $provider->getKey(),
                'provider_location_id' => $location->providerLocationId,
            ]);

            // Provider-owned facts only. `enabled` is the operator's and is
            // deliberately absent from this list: a sync that re-enabled a
            // location somebody switched off during an incident would undo the
            // decision the switch exists to make.
            $row->fill([
                'name' => $location->name,
                'country_code' => $location->countryCode,
                'city' => $location->city,
                'available' => $location->available,
                'metadata' => $location->metadata->toArray(),
            ])->save();

            $report->locations++;
        }

        // Reached only after a complete, successful fetch. A location the
        // provider no longer lists is marked unavailable rather than deleted:
        // orders and servers reference it, and history has to stay readable.
        $withdrawn = ProviderLocation::query()
            ->where('provider_id', $provider->getKey())
            ->where('available', true)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('provider_location_id', $seen))
            ->update(['available' => false, 'updated_at' => now()]);

        $report->locationsWithdrawn = $withdrawn;
    }

    /**
     * @param  list<ProviderPlanData>  $plans
     */
    private function applyPlans(Provider $provider, array $plans, CatalogSyncReport $report): void
    {
        foreach ($plans as $plan) {
            $row = ProviderPlan::query()->firstOrNew([
                'provider_id' => $provider->getKey(),
                'provider_plan_id' => $plan->providerPlanId,
            ]);

            $row->fill([
                'name' => $plan->name,
                'vcpu' => $plan->vcpu,
                'ram_mb' => $plan->ramMb,
                'disk_gb' => $plan->diskGb,
                'bandwidth_gb' => $plan->bandwidthGb,
                // Decimal strings throughout. This is the plan-level headline
                // cost; what a sale is actually priced against is the
                // per-location figure, written by the pricing sync.
                'provider_price_monthly' => $plan->monthlyPrice->amount,
                'provider_price_hourly' => $plan->hourlyPrice?->amount,
                'provider_currency' => $plan->monthlyPrice->currency,
                'metadata' => $plan->metadata->toArray(),
            ])->save();

            $report->plans++;
        }

        // Plans absent from the response are left exactly as they are. A
        // product still points at one, and a machine somebody bought still runs
        // on it.
    }

    /**
     * @param  list<ProviderImageData>  $images
     */
    private function applyImages(Provider $provider, array $images, CatalogSyncReport $report): void
    {
        $seen = [];

        foreach ($images as $image) {
            $seen[] = $image->providerImageId;

            $row = ProviderImage::query()->firstOrNew([
                'provider_id' => $provider->getKey(),
                'provider_image_id' => $image->providerImageId,
            ]);

            $row->fill([
                'name' => $image->name,
                'os_family' => $image->osFamily,
                'version' => $image->version,
                'architecture' => $image->architecture,
                'deprecated' => $image->deprecated,
                'metadata' => $image->metadata->toArray(),
            ])->save();

            $report->images++;
        }

        // An image that has vanished from a complete listing is marked
        // deprecated rather than deleted or disabled. A server was built from
        // it and a product may still name it as a default; what must stop is
        // presenting it as a healthy choice for a new sale.
        $deprecated = ProviderImage::query()
            ->where('provider_id', $provider->getKey())
            ->where('deprecated', false)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('provider_image_id', $seen))
            ->update(['deprecated' => true, 'updated_at' => now()]);

        $report->imagesDeprecated = $deprecated;
    }

    /**
     * @param  list<ProviderPricingData>  $pricing
     */
    private function applyPricing(Provider $provider, array $pricing, PricingSyncReport $report): void
    {
        // Native provider identifiers to local rows, resolved once.
        $plans = ProviderPlan::query()
            ->where('provider_id', $provider->getKey())
            ->pluck('id', 'provider_plan_id');

        $locations = ProviderLocation::query()
            ->where('provider_id', $provider->getKey())
            ->pluck('id', 'provider_location_id');

        // Which products sit on which plan, so a price can find the rows that
        // are actually on sale.
        $productsByPlan = [];

        foreach (Product::query()->where('provider_id', $provider->getKey())->get() as $product) {
            $productsByPlan[(int) $product->provider_plan_id][] = (int) $product->getKey();
        }

        $priced = [];

        foreach ($pricing as $pair) {
            $planId = $plans[$pair->providerPlanId] ?? null;
            $locationId = $locations[$pair->providerLocationId] ?? null;

            if ($planId === null || $locationId === null) {
                // The provider offers something this catalogue has never
                // imported. Not an error, and emphatically not a reason to
                // create anything: deciding to sell is an operator's call.
                continue;
            }

            $products = $productsByPlan[(int) $planId] ?? [];

            if ($products === []) {
                continue;
            }

            // Every priced pair is remembered whether or not a row exists for
            // it, so the clearing pass below cannot mistake "priced, not sold
            // here" for "no longer priced".
            $priced[] = (int) $planId.':'.(int) $locationId;

            $updated = ProductLocationPrice::query()
                ->whereIn('product_id', $products)
                ->where('provider_location_id', $locationId)
                ->update([
                    // Cost only. The selling price is a business decision and
                    // is never computed from a provider's figure here.
                    'provider_cost_snapshot' => $pair->monthlyPrice->amount,
                    'provider_currency' => $pair->monthlyPrice->currency,
                    'updated_at' => now(),
                ]);

            $report->costsUpdated += $updated;
        }

        $report->costsCleared = $this->clearWithdrawnCosts($provider, $priced, $productsByPlan);
    }

    /**
     * Null the cost of a pair the provider no longer prices.
     *
     * Fail-closed, and only ever from a complete successful snapshot. A missing
     * provider cost makes `PricingService` refuse a new sale, which is the
     * right outcome: selling at a price computed from a cost nobody can confirm
     * is how a product is sold below what it costs to run.
     *
     * Nothing else is touched. The product stays active, the row stays active,
     * the selling price stays put, and the location's switch is the operator's.
     * A later successful sync repopulates the cost.
     *
     * @param  list<string>  $priced
     * @param  array<int, list<int>>  $productsByPlan
     */
    private function clearWithdrawnCosts(Provider $provider, array $priced, array $productsByPlan): int
    {
        $keyed = array_flip($priced);
        $cleared = 0;

        foreach ($productsByPlan as $planId => $products) {
            $rows = ProductLocationPrice::query()
                ->whereIn('product_id', $products)
                ->whereNotNull('provider_cost_snapshot')
                ->get();

            foreach ($rows as $row) {
                if (array_key_exists($planId.':'.(int) $row->provider_location_id, $keyed)) {
                    continue;
                }

                $row->forceFill(['provider_cost_snapshot' => null])->save();
                $cleared++;
            }
        }

        return $cleared;
    }
}
