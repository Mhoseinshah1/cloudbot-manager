<?php

use App\Exceptions\ProviderAuthenticationException;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderCatalogSync;
use App\Models\ProviderCredential;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\ProviderPlanPrice;
use App\Providers\Cloud\HetznerProvider;
use App\Services\CatalogSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\HetznerApiFixtures as F;

function hetznerProviderRow(array $attributes = []): Provider
{
    return Provider::create([
        'code' => 'hetzner',
        'name' => 'Hetzner Cloud',
        'class' => HetznerProvider::class,
        'enabled' => false, // sync must work even for disabled providers
        'capabilities' => (new HetznerProvider)->capabilities(),
        'settings' => ['base_url' => F::BASE_URL, 'retry_attempts' => 1, 'retry_delay_ms' => 1],
        ...$attributes,
    ]);
}

function fakeHetznerCatalog(): void
{
    Http::fake([
        'api.hetzner.test/v1/locations*' => fn (Request $request) => Http::response(F::locationsResponse((int) ($request['page'] ?? 1), 2)),
        'api.hetzner.test/v1/server_types*' => fn (Request $request) => Http::response(F::serverTypesResponse((int) ($request['page'] ?? 1), 2)),
        'api.hetzner.test/v1/pricing' => Http::response(F::pricingResponse()),
        'api.hetzner.test/v1/images*' => fn (Request $request) => Http::response(F::imagesResponse((int) ($request['page'] ?? 1), 2)),
    ]);
}

it('syncs the full Hetzner catalog from mocked multi-page responses', function () {
    fakeHetznerCatalog();

    $provider = hetznerProviderRow();
    ProviderCredential::create([
        'provider_id' => $provider->id,
        'name' => 'Production',
        'credentials' => ['token' => F::TOKEN],
        'is_active' => true,
    ]);

    $sync = app(CatalogSyncService::class)->sync($provider);

    expect($sync->status)->toBe(ProviderCatalogSync::STATUS_COMPLETED);
    expect($sync->locations_count)->toBe(5);
    expect($sync->plans_count)->toBe(5);
    expect($sync->images_count)->toBe(6);
    expect($sync->pricing_count)->toBeGreaterThanOrEqual(19);

    expect(ProviderLocation::where('provider_id', $provider->id)->count())->toBe(5);
    expect(ProviderPlan::where('provider_id', $provider->id)->count())->toBe(5);
    expect(ProviderImage::where('provider_id', $provider->id)->count())->toBe(6);

    // Per-location prices/availability merged from /pricing + server-type catalog.
    expect(ProviderPlanPrice::count())->toBe(19);

    // Multi-page pagination was actually exercised.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'server_types') && $request['page'] === 2);
});

it('marks globally and per-location deprecated resources', function () {
    fakeHetznerCatalog();

    $provider = hetznerProviderRow();
    ProviderCredential::create([
        'provider_id' => $provider->id,
        'name' => 'Production',
        'credentials' => ['token' => F::TOKEN],
        'is_active' => true,
    ]);

    app(CatalogSyncService::class)->sync($provider);

    $cx32 = ProviderPlan::where('provider_id', $provider->id)->where('provider_plan_id', 'cx32')->first();
    expect($cx32->deprecated)->toBeTrue();

    $cx22 = ProviderPlan::where('provider_id', $provider->id)->where('provider_plan_id', 'cx22')->first();
    $hil = ProviderLocation::where('provider_id', $provider->id)->where('provider_location_id', 'hil')->first();

    $hilPrice = ProviderPlanPrice::where('provider_plan_id', $cx22->id)
        ->where('provider_location_id', $hil->id)
        ->first();

    expect($hilPrice)->not->toBeNull();
    expect($hilPrice->deprecated)->toBeTrue();

    $fsnPrice = ProviderPlanPrice::where('provider_plan_id', $cx22->id)
        ->where('provider_location_id', ProviderLocation::where('provider_location_id', 'fsn1')->first()->id)
        ->first();
    expect($fsnPrice->deprecated)->toBeFalse();
});

it('is safe to rerun and never duplicates or clobbers admin choices', function () {
    fakeHetznerCatalog();

    $provider = hetznerProviderRow();
    ProviderCredential::create([
        'provider_id' => $provider->id,
        'name' => 'Production',
        'credentials' => ['token' => F::TOKEN],
        'is_active' => true,
    ]);

    $service = app(CatalogSyncService::class);
    $service->sync($provider);

    // Admin disables a location and a plan — the next sync must preserve that.
    $location = ProviderLocation::where('provider_id', $provider->id)->where('provider_location_id', 'fsn1')->first();
    $location->update(['enabled' => false]);

    $plan = ProviderPlan::where('provider_id', $provider->id)->where('provider_plan_id', 'cpx21')->first();
    $plan->update(['enabled' => false]);

    $service->sync($provider);

    expect(ProviderLocation::where('provider_id', $provider->id)->count())->toBe(5);
    expect(ProviderPlan::where('provider_id', $provider->id)->count())->toBe(5);
    expect(ProviderImage::where('provider_id', $provider->id)->count())->toBe(6);

    expect($location->fresh()->enabled)->toBeFalse();
    expect($plan->fresh()->enabled)->toBeFalse();
});

it('never deletes local products when the provider catalog changes', function () {
    fakeHetznerCatalog();

    $provider = hetznerProviderRow();
    ProviderCredential::create([
        'provider_id' => $provider->id,
        'name' => 'Production',
        'credentials' => ['token' => F::TOKEN],
        'is_active' => true,
    ]);

    $service = app(CatalogSyncService::class);
    $service->sync($provider);

    $plan = ProviderPlan::where('provider_id', $provider->id)->first();

    Product::create([
        'provider_id' => $provider->id,
        'provider_plan_id' => $plan->id,
        'name' => 'Starter VPS',
        'slug' => 'starter-vps',
        'status' => 'active',
        'billing_cycle' => 'monthly',
        'markup_strategy' => 'percentage',
        'markup_value' => 20,
        'price_toman' => 399000,
        'enabled' => true,
    ]);

    $service->sync($provider);

    expect(Product::where('provider_id', $provider->id)->count())->toBe(1);
    expect(Product::first()->name)->toBe('Starter VPS');
});

it('records sync failures with errors on the sync record', function () {
    Http::fake([
        'api.hetzner.test/v1/locations*' => Http::response(F::error(401, 'unauthorized', 'unable to authorize you'), 401),
    ]);

    $provider = hetznerProviderRow();
    ProviderCredential::create([
        'provider_id' => $provider->id,
        'name' => 'Production',
        'credentials' => ['token' => 'wrong-token'],
        'is_active' => true,
    ]);

    expect(fn () => app(CatalogSyncService::class)->sync($provider))
        ->toThrow(ProviderAuthenticationException::class);

    $sync = ProviderCatalogSync::latest()->first();
    expect($sync->status)->toBe(ProviderCatalogSync::STATUS_FAILED);
    expect($sync->errors)->not->toBeNull();
});
