<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use App\Models\Product;
use App\Models\ProductLocationPrice;
use App\Models\Provider;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;

/**
 * Builds a coherent catalog for a test, and lets one piece of it be wrong.
 *
 * A sellable product needs a provider, a plan, a location, an image and a price
 * row that all agree with each other. Assembling that by hand in every test
 * would bury the one thing each test is actually about; here the default is
 * correct and the test states its single deviation.
 */
final class CatalogBuilder
{
    public Provider $provider;

    public ProviderPlan $plan;

    public ProviderLocation $location;

    public ProviderImage $image;

    public Product $product;

    public ProductLocationPrice $price;

    private function __construct() {}

    /**
     * A complete, internally consistent, sellable catalog.
     */
    public static function make(
        string $currency = 'EUR',
        string $providerCost = '4.550000',
        int $sellingPriceToman = 1_500_000,
    ): self {
        $self = new self;

        $self->provider = Provider::factory()->create(['code' => 'fake-'.bin2hex(random_bytes(4))]);
        $self->plan = $self->planFor($self->provider, $currency, $providerCost);
        $self->location = $self->locationFor($self->provider);
        $self->image = $self->imageFor($self->provider);

        $self->product = Product::factory()->create([
            'provider_id' => $self->provider->getKey(),
            'provider_plan_id' => $self->plan->getKey(),
        ]);

        $self->price = ProductLocationPrice::factory()->create([
            'product_id' => $self->product->getKey(),
            'provider_location_id' => $self->location->getKey(),
            'default_image_id' => $self->image->getKey(),
            'selling_price_toman' => $sellingPriceToman,
            'provider_cost_snapshot' => $providerCost,
            'provider_currency' => $currency,
        ]);

        return $self;
    }

    /**
     * A second provider, with its own plan, location and image.
     *
     * For the cross-provider tests: the rows are individually valid, which is
     * what makes pointing at them a mistake the database cannot catch.
     */
    public function foreignProvider(string $currency = 'EUR'): self
    {
        $other = new self;
        $other->provider = Provider::factory()->create(['code' => 'other-'.bin2hex(random_bytes(4))]);
        $other->plan = $other->planFor($other->provider, $currency, '9.990000');
        $other->location = $other->locationFor($other->provider);
        $other->image = $other->imageFor($other->provider);

        return $other;
    }

    private function planFor(Provider $provider, string $currency, string $cost): ProviderPlan
    {
        return ProviderPlan::query()->create([
            'provider_id' => $provider->getKey(),
            'provider_plan_id' => 'cx22-'.bin2hex(random_bytes(3)),
            'name' => 'CX22',
            'vcpu' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'provider_price_monthly' => $cost,
            'provider_currency' => $currency,
        ]);
    }

    private function locationFor(Provider $provider): ProviderLocation
    {
        return ProviderLocation::query()->create([
            'provider_id' => $provider->getKey(),
            'provider_location_id' => 'nbg1-'.bin2hex(random_bytes(3)),
            'name' => 'Nuremberg',
            'country_code' => 'DE',
            'city' => 'Nuremberg',
        ]);
    }

    private function imageFor(Provider $provider): ProviderImage
    {
        return ProviderImage::query()->create([
            'provider_id' => $provider->getKey(),
            'provider_image_id' => 'ubuntu-'.bin2hex(random_bytes(3)),
            'name' => 'Ubuntu 24.04',
            'os_family' => 'ubuntu',
            'version' => '24.04',
            'architecture' => 'x86',
        ]);
    }
}
