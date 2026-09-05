<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\SaleRefusalReason;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Setting;
use App\Models\User;
use App\Pricing\Exceptions\SaleNotAvailable;
use App\Pricing\ExchangeRateService;
use App\Pricing\PricingService;
use App\Settings\Exceptions\UnauthorizedSettingChange;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Catalog\CatalogBuilder;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->pricing = app(PricingService::class);
    $this->settings = app(SettingsService::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(AdminRole::Owner->value);

    $this->catalog = CatalogBuilder::make();
    app(ExchangeRateService::class)->recordManualRate('EUR', '90000', $this->owner);
});

function killSwitchRefusal(): SaleRefusalReason
{
    try {
        test()->pricing->quoteNewSale(test()->catalog->price);
    } catch (SaleNotAvailable $refusal) {
        return $refusal->reason;
    }

    test()->fail('The sale was quoted when it should have been refused.');
}

/** The catalog is fine; only the settings differ between these tests. */
function configure(?bool $salesEnabled, ?int $maxAge): void
{
    if ($salesEnabled !== null) {
        test()->settings->set(SettingKey::SalesEnabled, $salesEnabled, test()->owner);
    }

    if ($maxAge !== null) {
        test()->settings->set(SettingKey::FxMaxAgeMinutes, $maxAge, test()->owner);
    }
}

it('blocks a new sale when sales are explicitly disabled', function (): void {
    configure(salesEnabled: false, maxAge: 120);

    expect(killSwitchRefusal())->toBe(SaleRefusalReason::SalesDisabled);
});

it('permits a new sale when sales are explicitly enabled', function (): void {
    configure(salesEnabled: true, maxAge: 120);

    expect($this->pricing->quoteNewSale($this->catalog->price)->sellingPriceToman)->toBe(1_500_000);
});

it('blocks a new sale when the sales setting has never been set', function (): void {
    // Nothing about a missing row says selling is safe. Somebody has to have
    // said so.
    configure(salesEnabled: null, maxAge: 120);

    expect(Setting::query()->where('key', SettingKey::SalesEnabled->value)->exists())->toBeFalse()
        ->and(killSwitchRefusal())->toBe(SaleRefusalReason::SalesConfigurationMissing);
});

it('blocks a new sale when the freshness threshold has never been set', function (): void {
    // Without a threshold there is no basis for calling any rate fresh.
    configure(salesEnabled: true, maxAge: null);

    expect(killSwitchRefusal())->toBe(SaleRefusalReason::SalesConfigurationMissing);
});

it('blocks a new sale on a malformed sales setting', function (): void {
    // "yes" is a misconfiguration. Reading it as true would switch selling
    // back on because somebody typed the wrong word.
    configure(salesEnabled: true, maxAge: 120);

    foreach (['yes', 'TRUE', '1', '', 'on'] as $bad) {
        Setting::query()->where('key', SettingKey::SalesEnabled->value)->update(['value' => $bad]);

        expect(killSwitchRefusal())->toBe(SaleRefusalReason::SalesConfigurationMissing, "value {$bad}");
    }
});

it('blocks a new sale on a malformed freshness threshold', function (): void {
    configure(salesEnabled: true, maxAge: 120);

    foreach (['abc', '12 minutes', '', 'null'] as $bad) {
        Setting::query()->where('key', SettingKey::FxMaxAgeMinutes->value)->update(['value' => $bad]);

        expect(killSwitchRefusal())->toBe(SaleRefusalReason::SalesConfigurationMissing, "value {$bad}");
    }
});

it('blocks a new sale on a negative freshness threshold', function (): void {
    configure(salesEnabled: true, maxAge: 120);
    Setting::query()->where('key', SettingKey::FxMaxAgeMinutes->value)->update(['value' => '-5']);

    expect(killSwitchRefusal())->toBe(SaleRefusalReason::SalesConfigurationMissing);
});

it('blocks a new sale when a setting is stored under the wrong type', function (): void {
    configure(salesEnabled: true, maxAge: 120);
    Setting::query()->where('key', SettingKey::SalesEnabled->value)
        ->update(['type' => SettingType::String->value]);

    expect(killSwitchRefusal())->toBe(SaleRefusalReason::SalesConfigurationMissing);
});

it('changes nothing about the catalog when a sale is refused', function (): void {
    configure(salesEnabled: false, maxAge: 120);

    // Compared as the rows the database actually holds, so a refusal that
    // touched anything at all would show up here.
    $product = (array) DB::table('products')->where('id', $this->catalog->product->id)->first();
    $price = (array) DB::table('product_location_prices')->where('id', $this->catalog->price->id)->first();
    $rates = DB::table('exchange_rates')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();

    expect(killSwitchRefusal())->toBe(SaleRefusalReason::SalesDisabled);

    expect((array) DB::table('products')->where('id', $this->catalog->product->id)->first())->toBe($product)
        ->and((array) DB::table('product_location_prices')->where('id', $this->catalog->price->id)->first())->toBe($price)
        ->and(DB::table('exchange_rates')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all())->toBe($rates);
});

it('refuses a setting change from anyone without settings.manage', function (): void {
    foreach ([AdminRole::Support, AdminRole::Finance] as $role) {
        $actor = User::factory()->create();
        $actor->assignRole($role->value);

        expect(fn () => $this->settings->set(SettingKey::SalesEnabled, true, $actor))
            ->toThrow(UnauthorizedSettingChange::class);
    }

    expect(Setting::query()->count())->toBe(0);
});
