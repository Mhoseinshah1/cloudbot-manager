<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\SaleRefusalReason;
use App\Enums\SettingKey;
use App\Models\ProductLocationPrice;
use App\Models\User;
use App\Pricing\Exceptions\SaleNotAvailable;
use App\Pricing\ExchangeRateService;
use App\Pricing\PricingService;
use App\Settings\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Catalog\CatalogBuilder;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->pricing = app(PricingService::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(AdminRole::Owner->value);

    // A configured, selling business with a fresh rate. Each test then breaks
    // exactly one thing.
    app(SettingsService::class)->set(SettingKey::SalesEnabled, true, $this->owner);
    app(SettingsService::class)->set(SettingKey::FxMaxAgeMinutes, 120, $this->owner);
    app(ExchangeRateService::class)->recordManualRate('EUR', '92345.12345678', $this->owner);

    $this->catalog = CatalogBuilder::make();
});

/** The reason a quote was refused, as the stable enum rather than its message. */
function refusalFor(ProductLocationPrice $price): SaleRefusalReason
{
    try {
        test()->pricing->quoteNewSale($price);
    } catch (SaleNotAvailable $refusal) {
        return $refusal->reason;
    }

    test()->fail('The sale was quoted when it should have been refused.');
}

it('quotes an active monthly product', function (): void {
    $quote = $this->pricing->quoteNewSale($this->catalog->price);

    expect($quote->productId)->toBe($this->catalog->product->id)
        ->and($quote->productLocationPriceId)->toBe($this->catalog->price->id)
        ->and($quote->providerId)->toBe($this->catalog->provider->id)
        ->and($quote->providerCode)->toBe($this->catalog->provider->code)
        ->and($quote->providerPlanCode)->toBe($this->catalog->plan->provider_plan_id)
        ->and($quote->providerLocationCode)->toBe($this->catalog->location->provider_location_id)
        ->and($quote->defaultImageId)->toBe($this->catalog->image->id)
        ->and($quote->billingMode->value)->toBe('monthly')
        ->and($quote->billingCycle->value)->toBe('monthly');
});

it('refuses an inactive product', function (): void {
    $this->catalog->product->forceFill(['active' => false])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::UnavailableProduct);
});

it('refuses a disabled provider', function (): void {
    $this->catalog->provider->forceFill(['enabled' => false])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::UnavailableProduct);
});

it('refuses a disabled provider plan', function (): void {
    $this->catalog->plan->forceFill(['enabled' => false])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::UnavailableProduct);
});

it('refuses an inactive location price', function (): void {
    $this->catalog->price->forceFill(['active' => false])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::UnavailableLocation);
});

it('refuses a disabled location', function (): void {
    // Ours: an operator has chosen not to sell here.
    $this->catalog->location->forceFill(['enabled' => false])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::UnavailableLocation);
});

it('refuses an unavailable location', function (): void {
    // The provider's: it has no capacity right now.
    $this->catalog->location->forceFill(['available' => false])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::UnavailableLocation);
});

it('refuses a plan belonging to another provider', function (): void {
    // Provisioning this would create a server on the wrong account, against
    // the wrong credentials, at a cost nobody recorded.
    $other = $this->catalog->foreignProvider();
    $this->catalog->product->forceFill(['provider_plan_id' => $other->plan->getKey()])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::InvalidCatalogRelationship);
});

it('refuses a location belonging to another provider', function (): void {
    $other = $this->catalog->foreignProvider();
    $this->catalog->price->forceFill(['provider_location_id' => $other->location->getKey()])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::InvalidCatalogRelationship);
});

it('refuses a default image belonging to another provider', function (): void {
    $other = $this->catalog->foreignProvider();
    $this->catalog->price->forceFill(['default_image_id' => $other->image->getKey()])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::InvalidCatalogRelationship);
});

it('refuses a price whose currency disagrees with its plan', function (): void {
    // Converting the wrong currency yields a plausible number and a wrong margin.
    $this->catalog->price->forceFill(['provider_currency' => 'USD'])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::InvalidCatalogRelationship);
});

it('quotes with no default image at all', function (): void {
    $this->catalog->price->forceFill(['default_image_id' => null])->save();

    expect($this->pricing->quoteNewSale($this->catalog->price->fresh())->defaultImageId)->toBeNull();
});

it('refuses a second price for the same product and location', function (): void {
    expect(fn () => DB::transaction(fn () => ProductLocationPrice::factory()->create([
        'product_id' => $this->catalog->product->getKey(),
        'provider_location_id' => $this->catalog->location->getKey(),
    ])))->toThrow(QueryException::class);

    expect(ProductLocationPrice::query()->count())->toBe(1);
});

it('round-trips a selling price above the 32-bit range exactly', function (): void {
    // Ordinary Toman balances exceed 2^31; a price silently truncated to a
    // 32-bit column would undercharge every customer.
    $price = 9_876_543_210_123;
    $this->catalog->price->forceFill(['selling_price_toman' => $price])->save();

    $stored = ProductLocationPrice::query()->find($this->catalog->price->getKey());

    expect($stored->selling_price_toman)->toBeInt()->toBe($price)
        ->and((int) DB::table('product_location_prices')->where('id', $stored->id)->value('selling_price_toman'))
        ->toBe($price)
        ->and($this->pricing->quoteNewSale($stored)->sellingPriceToman)->toBe($price);
});

it('quotes exactly the configured selling price', function (): void {
    // The final customer figure is the one an operator set. Nothing derives it
    // from cost and nothing is added to it.
    $this->catalog->price->forceFill(['selling_price_toman' => 1_234_567])->save();

    $quote = $this->pricing->quoteNewSale($this->catalog->price->fresh());

    expect($quote->sellingPriceToman)->toBe(1_234_567)
        ->and($quote->toArray()['selling_price_toman'])->toBe(1_234_567);
});

it('adds no fee on top of the selling price', function (): void {
    // Release 1.0 folds provider add-ons into the configured figure. There is
    // no add-on engine, and a quote that quietly grew would be one.
    foreach ([1, 999, 1_000_000, 87_654_321] as $configured) {
        $this->catalog->price->forceFill(['selling_price_toman' => $configured])->save();

        expect($this->pricing->quoteNewSale($this->catalog->price->fresh())->sellingPriceToman)
            ->toBe($configured);
    }
});

it('reports a negative margin rather than repricing', function (): void {
    // A price below cost is a configuration problem for a person to see.
    // Correcting it here would hide it.
    $this->catalog->price->forceFill(['selling_price_toman' => 1_000])->save();

    $quote = $this->pricing->quoteNewSale($this->catalog->price->fresh());

    expect($quote->sellingPriceToman)->toBe(1_000)
        ->and($quote->isLossMaking())->toBeTrue()
        ->and($quote->grossMarginToman)->toStartWith('-')
        ->and($quote->grossMarginToman)->toBe('-419170.31172834900000');
});

it('refuses a sale when no provider cost has been recorded', function (): void {
    // Treating it as zero would report the entire price as profit.
    $this->catalog->price->forceFill(['provider_cost_snapshot' => null])->save();

    expect(refusalFor($this->catalog->price))->toBe(SaleRefusalReason::MissingProviderCost);
});

it('makes no network request while quoting', function (): void {
    // Pricing runs while a customer waits. A provider's API being slow must
    // never become this system being slow.
    Http::preventStrayRequests();

    $this->pricing->quoteNewSale($this->catalog->price);

    Http::assertNothingSent();
});

it('refuses a product whose location price row has gone', function (): void {
    $price = $this->catalog->price;
    $price->delete();

    expect(refusalFor($price))->toBe(SaleRefusalReason::UnavailableLocation);
});
