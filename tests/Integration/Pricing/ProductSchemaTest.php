<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use App\Models\Product;
use App\Models\ProductLocationPrice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Catalog\CatalogBuilder;

it('creates the phase 5 tables', function (): void {
    foreach (['products', 'product_location_prices', 'exchange_rates'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("expected {$table}");
    }
});

it('ships no table belonging to a later phase', function (): void {
    // The scope-creep guard, moved forward with the build. Server actions and
    // notification history arrived with the sales and management phase;
    // Hetzner's own tables and Release 1.1 billing have not.
    foreach ([
        'billing_charges', 'hetzner_server_types', 'usage_samples',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("{$table} belongs to a later phase");
    }
});

it('defaults a product to monthly billing', function (): void {
    $catalog = CatalogBuilder::make();

    expect($catalog->product->billing_mode)->toBe(BillingMode::Monthly)
        ->and($catalog->product->billing_cycle)->toBe(BillingCycle::Monthly);
});

it('refuses an hourly billing mode at the database level', function (): void {
    // Release 1.0 has no hourly behaviour anywhere. A row claiming otherwise
    // would be billed by code that does not exist.
    $catalog = CatalogBuilder::make();

    foreach (['hourly', 'hourly_capped', 'yearly', ''] as $bad) {
        expect(fn () => DB::transaction(fn () => DB::table('products')
            ->where('id', $catalog->product->id)
            ->update(['billing_mode' => $bad])))
            ->toThrow(QueryException::class, '', "billing_mode {$bad}");
    }

    expect(Product::query()->find($catalog->product->id)->billing_mode)->toBe(BillingMode::Monthly);
});

it('refuses an hourly billing cycle at the database level', function (): void {
    $catalog = CatalogBuilder::make();

    foreach (['hourly', 'yearly', ''] as $bad) {
        expect(fn () => DB::transaction(fn () => DB::table('products')
            ->where('id', $catalog->product->id)
            ->update(['billing_cycle' => $bad])))
            ->toThrow(QueryException::class, '', "billing_cycle {$bad}");
    }
});

it('offers only monthly in the billing enums', function (): void {
    // A case added here without a migration widening the CHECK constraint
    // would make the application believe in a value the database rejects.
    expect(BillingMode::values())->toBe(['monthly'])
        ->and(BillingCycle::values())->toBe(['monthly']);
});

it('carries no hourly or usage pricing columns', function (): void {
    foreach ([
        'hourly_price_toman', 'price_hourly_toman', 'hourly_cap_toman',
        'cycle_charged_toman', 'rounding_carry_micro_toman', 'usage_toman',
    ] as $column) {
        expect(Schema::hasColumn('product_location_prices', $column))->toBeFalse($column)
            ->and(Schema::hasColumn('products', $column))->toBeFalse($column);
    }
});

it('keeps a product when its provider is deleted', function (): void {
    // Restricted, not cascaded: a provider row disappearing must not silently
    // take the things customers can buy with it.
    $catalog = CatalogBuilder::make();

    expect(fn () => DB::transaction(fn () => $catalog->provider->delete()))
        ->toThrow(QueryException::class);

    expect(Product::query()->count())->toBe(1);
});

it('removes location prices with their product', function (): void {
    // These rows are the product's own pricing and mean nothing without it.
    // No financial history lives here.
    $catalog = CatalogBuilder::make();

    $catalog->product->delete();

    expect(ProductLocationPrice::query()->count())->toBe(0);
});

it('leaves the location without a default when the image is removed', function (): void {
    // An image being retired should not block the catalog.
    $catalog = CatalogBuilder::make();

    $catalog->image->delete();

    expect($catalog->price->fresh()->default_image_id)->toBeNull()
        ->and(ProductLocationPrice::query()->count())->toBe(1);
});

it('keeps a location price when its location is deleted', function (): void {
    $catalog = CatalogBuilder::make();

    expect(fn () => DB::transaction(fn () => $catalog->location->delete()))
        ->toThrow(QueryException::class);
});

it('indexes exchange rates for the current-rate lookup', function (): void {
    $indexes = collect(DB::select("select indexdef from pg_indexes where tablename = 'exchange_rates'"))
        ->pluck('indexdef')
        ->implode("\n");

    expect($indexes)->toContain('currency')
        ->and($indexes)->toContain('effective_from');
});

it('puts no unique constraint on exchange rate currency', function (): void {
    // Many rows per currency is the normal state: the table is a history.
    $unique = collect(DB::select("select indexdef from pg_indexes where tablename = 'exchange_rates'"))
        ->pluck('indexdef')
        ->filter(fn (string $def): bool => str_contains($def, 'UNIQUE'))
        ->filter(fn (string $def): bool => str_contains($def, 'currency'));

    expect($unique->all())->toBe([]);
});
