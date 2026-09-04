<?php

declare(strict_types=1);

use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What is actually for sale, and for how much.
 *
 * Distinct from the provider catalog on purpose. A provider plan is what a
 * provider offers us; a product is what a customer is offered, and the two
 * change for different reasons — a re-sync rewrites the first and must never
 * touch the second. The price a customer pays lives here, in whole Toman, and
 * is never derived from provider cost at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            // Restricted, not cascaded. A provider row disappearing must not
            // silently take the things customers can buy with it.
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_plan_id')->constrained('provider_plans')->restrictOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->boolean('active')->default(true);

            // Two columns, one value each in Release 1.0. They answer different
            // questions and stop agreeing in 1.1, so they are separate now
            // rather than after there are live products to migrate.
            $table->string('billing_mode', 20)->default(BillingMode::Monthly->value);
            $table->string('billing_cycle', 20)->default(BillingCycle::Monthly->value);

            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index(['active', 'sort_order']);
        });

        // The database refuses an hourly product, not just the application.
        // Release 1.0 has no hourly behaviour anywhere; a row claiming
        // otherwise would be priced and billed by code that does not exist.
        DB::statement($this->checkIn('products', 'billing_mode', BillingMode::values()));
        DB::statement($this->checkIn('products', 'billing_cycle', BillingCycle::values()));

        Schema::create('product_location_prices', function (Blueprint $table): void {
            $table->id();

            // Cascaded: these rows are the product's own pricing and mean
            // nothing without it. No financial history is held here.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_location_id')->constrained('provider_locations')->restrictOnDelete();

            $table->boolean('active')->default(true);

            // The customer price. Whole Toman in a BIGINT, read into PHP as an
            // int, never a float.
            $table->bigInteger('selling_price_toman');

            // What the provider charges us, in the provider's own currency and
            // its own decimal scale. A different kind of number from the one
            // above, and deliberately not stored as Toman: the conversion
            // depends on a rate that changes.
            $table->decimal('provider_cost_snapshot', 20, 6)->nullable();
            $table->string('provider_currency', 3);

            // Nulled rather than restricted: an image being retired should not
            // block the catalog, it should leave the location without a default.
            $table->foreignId('default_image_id')->nullable()->constrained('provider_images')->nullOnDelete();

            $table->timestamps();

            // One price per product per location. Two would make "the price"
            // a question with no answer.
            $table->unique(['product_id', 'provider_location_id']);
        });

        // A free or negative sale is not a price. Zero would also hide a
        // half-configured product behind a plausible-looking row.
        DB::statement(
            'ALTER TABLE product_location_prices
             ADD CONSTRAINT product_location_prices_selling_price_positive CHECK (selling_price_toman > 0)'
        );

        // Absent cost is allowed and blocks a sale. A negative one is not a
        // cost at all.
        DB::statement(
            'ALTER TABLE product_location_prices
             ADD CONSTRAINT product_location_prices_cost_non_negative
             CHECK (provider_cost_snapshot IS NULL OR provider_cost_snapshot >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_location_prices');
        Schema::dropIfExists('products');
    }

    /**
     * @param  list<string>  $values
     */
    private function checkIn(string $table, string $column, array $values): string
    {
        $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));

        return "ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list}))";
    }
};
