<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Provider;
use App\Models\ProviderCredential;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\Setting;
use App\Providers\Cloud\FakeProvider;
use App\Services\PricingService;
use Illuminate\Database\Seeder;

class FakeProviderSeeder extends Seeder
{
    public function run(): void
    {
        $fake = new FakeProvider;

        $provider = Provider::query()->updateOrCreate(
            ['code' => 'fake'],
            [
                'name' => 'Fake Provider (dev/test)',
                'class' => FakeProvider::class,
                'enabled' => true,
                'capabilities' => $fake->capabilities(),
            ]
        );

        ProviderCredential::query()->updateOrCreate(
            ['provider_id' => $provider->id, 'name' => 'sandbox'],
            [
                'credentials' => ['token' => 'fake-token'],
                'is_active' => true,
            ]
        );

        foreach ($fake->getLocations() as $location) {
            ProviderLocation::query()->updateOrCreate(
                ['provider_id' => $provider->id, 'provider_location_id' => $location->id],
                [
                    'name' => $location->name,
                    'country_code' => $location->countryCode,
                    'city' => $location->city,
                    'enabled' => true,
                    'metadata' => $location->metadata,
                ]
            );
        }

        foreach ($fake->getPlans() as $plan) {
            ProviderPlan::query()->updateOrCreate(
                ['provider_id' => $provider->id, 'provider_plan_id' => $plan->id],
                [
                    'name' => $plan->name,
                    'vcpu' => $plan->vcpu,
                    'ram_mb' => $plan->ramMb,
                    'disk_gb' => $plan->diskGb,
                    'bandwidth_gb' => $plan->bandwidthGb,
                    'price_monthly' => $plan->priceMonthly,
                    'currency' => $plan->currency,
                    'price_hourly' => $plan->priceHourly,
                    'enabled' => true,
                    'metadata' => $plan->metadata,
                ]
            );
        }

        foreach ($fake->getImages() as $image) {
            ProviderImage::query()->updateOrCreate(
                ['provider_id' => $provider->id, 'provider_image_id' => $image->id],
                [
                    'name' => $image->name,
                    'os_family' => $image->osFamily,
                    'os_distro' => $image->osDistro,
                    'version' => $image->version,
                    'architecture' => $image->architecture,
                    'enabled' => true,
                    'metadata' => $image->metadata,
                ]
            );
        }

        $plan = ProviderPlan::query()
            ->where('provider_id', $provider->id)
            ->where('provider_plan_id', 'cpx21')
            ->firstOrFail();

        $product = Product::query()->updateOrCreate(
            ['slug' => 'vps-cx21'],
            [
                'provider_id' => $provider->id,
                'provider_plan_id' => $plan->id,
                'name' => 'VPS CX21',
                'description' => '2 vCPU, 4 GB RAM, 80 GB SSD — 20 TB traffic.',
                'status' => Product::STATUS_ACTIVE,
                'billing_cycle' => Product::BILLING_MONTHLY,
                'markup_strategy' => Product::MARKUP_PERCENTAGE,
                'markup_value' => 15,
                'enabled' => true,
                'lifecycle_policy' => [
                    'notify_days' => 7,
                    'power_off_days' => 3,
                    'suspend_days' => null,
                    'delete_days' => 7,
                ],
            ]
        );

        $price = app(PricingService::class)->compute($plan, $product);

        ProductPrice::query()->updateOrCreate(
            ['product_id' => $product->id, 'billing_cycle' => Product::BILLING_MONTHLY],
            [
                'price_toman' => $price['selling_price'],
                'provider_cost' => $price['provider_cost'],
                'provider_currency' => $price['provider_currency'],
                'exchange_rate' => $price['exchange_rate'],
                'local_cost' => $price['local_cost'],
                'gross_margin' => $price['gross_margin'],
                'valid_from' => now(),
            ]
        );

        Setting::query()->updateOrCreate(
            ['key' => 'exchange_rate_eur_toman'],
            ['value' => 450000, 'group' => 'pricing']
        );
    }
}
