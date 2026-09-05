<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\SettingKey;
use App\Models\ProductLocationPrice;
use App\Models\User;
use App\Pricing\Data\PriceQuote;
use App\Pricing\ExchangeRateService;
use App\Pricing\PricingService;
use App\Settings\SettingsService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\Support\Catalog\CatalogBuilder;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->pricing = app(PricingService::class);
    $this->rates = app(ExchangeRateService::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(AdminRole::Owner->value);

    app(SettingsService::class)->set(SettingKey::SalesEnabled, true, $this->owner);
    app(SettingsService::class)->set(SettingKey::FxMaxAgeMinutes, 1_440, $this->owner);
});

/** A quote for one cost, rate and price, with everything else out of the way. */
function quoteFor(string $cost, string $rate, int $sellingPrice, string $currency = 'EUR'): PriceQuote
{
    $catalog = CatalogBuilder::make($currency, $cost, $sellingPrice);
    test()->rates->recordManualRate($currency, $rate, test()->owner);

    return test()->pricing->quoteNewSale($catalog->price);
}

it('round-trips a provider cost through PostgreSQL exactly', function (): void {
    // Values chosen because a float would visibly mangle them.
    foreach (['0.100000', '0.200000', '4.550000', '19.990000', '99999999999999.999999'] as $cost) {
        $catalog = CatalogBuilder::make('EUR', $cost);

        expect(DB::table('product_location_prices')->where('id', $catalog->price->id)->value('provider_cost_snapshot'))
            ->toBe($cost)
            ->and($catalog->price->fresh()->provider_cost_snapshot)->toBe($cost);
    }
});

it('round-trips an eight-place rate through PostgreSQL exactly', function (): void {
    foreach (['0.00000001', '92345.12345678', '999999999999.99999999'] as $value) {
        $rate = $this->rates->recordManualRate('EUR', $value, $this->owner);

        expect(DB::table('exchange_rates')->where('id', $rate->id)->value('rate_to_toman'))->toBe($value);
    }
});

it('multiplies cost by rate exactly', function (): void {
    // 0.1 × 0.2 is the classic case: in binary floating point this is
    // 0.020000000000000004, and a margin built on that is wrong in a way
    // nobody notices until the accounts do not add up.
    $quote = quoteFor('0.100000', '0.20000000', 1_000);

    expect($quote->convertedProviderCostToman)
        ->toBe((string) BigDecimal::of('0.100000')->multipliedBy('0.20000000'))
        ->and($quote->convertedProviderCostToman)->toBe('0.02000000000000');
});

it('preserves fractional Toman rather than rounding it', function (): void {
    // The specification defines no rule for turning fractional Toman into
    // whole Toman. Choosing one here would silently decide a business question
    // on every sale, so the exact value is carried instead.
    $quote = quoteFor('4.550000', '92345.12345678', 1_500_000);

    expect($quote->convertedProviderCostToman)->toBe('420170.31172834900000')
        ->and($quote->convertedProviderCostToman)->toContain('.')
        ->and(BigDecimal::of($quote->convertedProviderCostToman)->isEqualTo('420170.311728349'))->toBeTrue();
});

it('computes gross margin exactly', function (): void {
    $quote = quoteFor('4.550000', '92345.12345678', 1_500_000);

    $expected = BigDecimal::of(1_500_000)->minus(BigDecimal::of('4.550000')->multipliedBy('92345.12345678'));

    expect($quote->grossMarginToman)->toBe((string) $expected)
        ->and($quote->grossMarginToman)->toBe('1079829.68827165100000');
});

it('computes a negative margin exactly', function (): void {
    $quote = quoteFor('10.000000', '95000.00000000', 500_000);

    expect($quote->isLossMaking())->toBeTrue()
        ->and($quote->grossMarginToman)->toBe('-450000.00000000000000')
        ->and(BigDecimal::of($quote->grossMarginToman)->isEqualTo('-450000'))->toBeTrue();
});

it('keeps every money value in the quote out of float', function (): void {
    // A float anywhere in this DTO would mean the exactness above was thrown
    // away on the way out.
    $quote = quoteFor('4.550000', '92345.12345678', 9_876_543_210_123);

    expect($quote->providerCost)->toBeString()
        ->and($quote->exchangeRate)->toBeString()
        ->and($quote->convertedProviderCostToman)->toBeString()
        ->and($quote->grossMarginToman)->toBeString()
        // Customer money is the one value that is an int, because Toman has
        // no fractions and an int cannot acquire any.
        ->and($quote->sellingPriceToman)->toBeInt()->toBe(9_876_543_210_123);

    foreach ($quote->toArray() as $key => $value) {
        expect(is_float($value))->toBeFalse("{$key} is a float");
    }
});

it('carries a selling price above the 32-bit range through the quote', function (): void {
    $quote = quoteFor('1.000000', '1.00000000', 4_294_967_296);

    expect($quote->sellingPriceToman)->toBe(4_294_967_296)
        ->and($quote->grossMarginToman)->toBe('4294967295.00000000000000');
});

it('never widens a stored cost into a different number', function (): void {
    // A cost the database stores at scale 6 must come back as that same
    // decimal, not as something equal-ish to it.
    $catalog = CatalogBuilder::make('EUR', '0.000001');
    $this->rates->recordManualRate('EUR', '1.00000000', $this->owner);

    $quote = $this->pricing->quoteNewSale($catalog->price);

    expect($quote->providerCost)->toBe('0.000001')
        ->and(BigDecimal::of($quote->convertedProviderCostToman)->isEqualTo('0.000001'))->toBeTrue();
});

it('uses no float anywhere in the phase 5 production code', function (): void {
    // A grep is a blunt instrument, but the thing it is looking for is exactly
    // the thing that silently destroys money.
    $paths = [
        app_path('Pricing'),
        app_path('Settings'),
        app_path('Models/Product.php'),
        app_path('Models/ProductLocationPrice.php'),
        app_path('Models/ExchangeRate.php'),
    ];

    $offenders = [];

    foreach ($paths as $path) {
        $files = is_dir($path)
            ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)))
            : [new SplFileInfo($path)];

        foreach ($files as $file) {
            if (! str_ends_with((string) $file, '.php')) {
                continue;
            }

            $source = (string) file_get_contents((string) $file);

            foreach (['(float)', '(double)', 'floatval', 'round(', 'floor(', 'ceil('] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = basename((string) $file).' contains '.$needle;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('stores the selling price as a bigint column', function (): void {
    $type = DB::selectOne(
        "select data_type from information_schema.columns
         where table_name = 'product_location_prices' and column_name = 'selling_price_toman'"
    );

    expect($type->data_type)->toBe('bigint');
});

it('stores the rate as numeric with the required precision', function (): void {
    $column = DB::selectOne(
        "select data_type, numeric_precision, numeric_scale from information_schema.columns
         where table_name = 'exchange_rates' and column_name = 'rate_to_toman'"
    );

    expect($column->data_type)->toBe('numeric')
        ->and((int) $column->numeric_precision)->toBe(20)
        ->and((int) $column->numeric_scale)->toBe(8);
});

it('stores the provider cost as numeric', function (): void {
    $column = DB::selectOne(
        "select data_type, numeric_precision, numeric_scale from information_schema.columns
         where table_name = 'product_location_prices' and column_name = 'provider_cost_snapshot'"
    );

    expect($column->data_type)->toBe('numeric')
        ->and((int) $column->numeric_precision)->toBe(20)
        ->and((int) $column->numeric_scale)->toBe(6);
});

it('refuses a zero or negative selling price in the database', function (): void {
    $catalog = CatalogBuilder::make();

    foreach ([0, -1] as $bad) {
        expect(fn () => DB::transaction(fn () => DB::table('product_location_prices')
            ->where('id', $catalog->price->id)
            ->update(['selling_price_toman' => $bad])))
            ->toThrow(Illuminate\Database\QueryException::class);
    }

    expect(ProductLocationPrice::query()->find($catalog->price->id)->selling_price_toman)->toBe(1_500_000);
});

it('refuses a negative provider cost in the database', function (): void {
    $catalog = CatalogBuilder::make();

    expect(fn () => DB::transaction(fn () => DB::table('product_location_prices')
        ->where('id', $catalog->price->id)
        ->update(['provider_cost_snapshot' => '-0.000001'])))
        ->toThrow(Illuminate\Database\QueryException::class);
});
