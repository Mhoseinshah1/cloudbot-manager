<?php

declare(strict_types=1);

use App\Enums\ExchangeRateSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a foreign currency was worth, and when.
 *
 * A history, not a current value. Every row stays as written: an order priced
 * last month was priced at last month's rate, and rewriting that rate would
 * rewrite what the customer was told. So there is deliberately no unique
 * constraint on currency — many rows per currency is the normal state — and a
 * new rate is an insert, never an update.
 *
 * `effective_from` is when a rate starts applying, which is not when the row
 * was created. A rate dated tomorrow is stored today and ignored until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();

            // ISO 4217, stored normalised upper-case by the service.
            $table->string('currency', 3);

            // NUMERIC(20,8) as the specification requires: enough scale for a
            // rate quoted to eight places, and exact. Read into PHP as a string
            // and computed on with arbitrary-precision decimals.
            $table->decimal('rate_to_toman', 20, 8);

            $table->string('source', 20);

            $table->timestamp('effective_from');

            // Who entered it. Nulled rather than cascaded: losing the
            // administrator must not delete the rate orders were priced at.
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The lookup this table exists for: newest applicable rate for one
            // currency at a moment in time.
            $table->index(['currency', 'effective_from']);
        });

        // A zero or negative rate is not a rate; it would price every sale at
        // nothing, or at less than nothing.
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_positive CHECK (rate_to_toman > 0)');

        $sources = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            ExchangeRateSource::values(),
        ));

        DB::statement("ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_source_check CHECK (source IN ({$sources}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
