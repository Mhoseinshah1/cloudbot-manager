<?php

declare(strict_types=1);

use App\Enums\OrderFailureCategory;
use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One customer's decision to buy one server.
 *
 * An order is two things at once, and the schema keeps them apart. Half of it
 * is a historical record — what was quoted, at what rate, against which terms —
 * and that half is frozen the moment the row is written, by a trigger, because
 * the customer was told those numbers and no later change may edit what they
 * were told. The other half is a lifecycle that legitimately moves: status,
 * attempts, the provisioning token Phase 7 commits, when it finished.
 *
 * One order becomes exactly one server. There is no quantity column, and adding
 * one later would be a different product, not a bigger number.
 */
return new class extends Migration
{
    /**
     * Written once, at creation, and never again.
     *
     * @var list<string>
     */
    private const IMMUTABLE = [
        'user_id', 'product_id', 'product_location_price_id', 'order_number',
        'total_toman', 'idempotency_key', 'cost_snapshot', 'pricing_snapshot',
        'aup_version', 'aup_accepted_at',
    ];

    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            // Restricted throughout. An order is financial evidence and must
            // outlive the customer record, the product and the price row it
            // was placed against.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_location_price_id')
                ->constrained('product_location_prices')->restrictOnDelete();

            // Customer-facing identity. Not derived from a counter: two workers
            // counting would collide, and the number is quoted to people.
            $table->string('order_number', 40)->unique();

            $table->string('status', 30)->default(OrderStatus::Pending->value);

            // What the customer is charged. Whole Toman, BIGINT, PHP int.
            $table->bigInteger('total_toman');

            $table->string('idempotency_key')->unique();

            // Set by whatever asks the customer to pay. No default: the
            // specification names no timeout, and inventing one would expire
            // real orders on a rule nobody agreed.
            $table->timestamp('awaiting_payment_expires_at')->nullable();

            // Phase 7 writes this before its first remote create call, and the
            // uniqueness is what stops one order producing two servers.
            $table->uuid('provisioning_uuid')->nullable()->unique();

            $table->string('failure_category', 40)->nullable();
            // Scrubbed before it gets here. Never a raw provider response.
            $table->text('failure_reason')->nullable();

            $table->unsignedInteger('attempts')->default(0);

            // What the provider costs and what the customer was quoted, exactly
            // as they stood. Decimals are strings inside the JSON.
            $table->jsonb('cost_snapshot');
            $table->jsonb('pricing_snapshot');

            // Which terms, and when they were accepted. The time is set by the
            // server; a customer-supplied timestamp proves nothing.
            $table->string('aup_version', 50);
            $table->timestamp('aup_accepted_at');

            $table->timestamp('provisioned_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->index(['status', 'awaiting_payment_expires_at']);
        });

        $this->checkIn('orders', 'status', OrderStatus::values());
        $this->checkIn('orders', 'failure_category', OrderFailureCategory::values(), nullable: true);

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_positive CHECK (total_toman > 0)');

        // The historical half, frozen in the database.
        //
        // The models refuse these edits too. This is the guard that still holds
        // for a query builder call or someone at a psql prompt, which is where
        // a well-meant "just fix the price on that one order" actually happens.
        // Lifecycle columns are untouched, so status, attempts, the
        // provisioning token and the rest move freely.
        $comparisons = implode("\n                OR ", array_map(
            static fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            self::IMMUTABLE,
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION orders_reject_snapshot_change() RETURNS trigger AS $$
            BEGIN
                IF {$comparisons}
                THEN
                    RAISE EXCEPTION 'orders: what the customer was quoted cannot be changed'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER orders_no_snapshot_change
                BEFORE UPDATE ON orders
                FOR EACH ROW EXECUTE FUNCTION orders_reject_snapshot_change();
        SQL);

        // Retained, like payments and invoices, and by the same function those
        // two already use rather than a second copy of the same rule.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER orders_no_delete
                BEFORE DELETE ON orders
                FOR EACH ROW EXECUTE FUNCTION financial_records_reject_delete();
        SQL);
    }

    public function down(): void
    {
        // The shared delete-guard function belongs to the migration that made
        // it, so only this table's own trigger and function go here.
        if (Schema::hasTable('orders')) {
            DB::unprepared('DROP TRIGGER IF EXISTS orders_no_delete ON orders');
            DB::unprepared('DROP TRIGGER IF EXISTS orders_no_snapshot_change ON orders');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS orders_reject_snapshot_change()');

        Schema::dropIfExists('orders');
    }

    /**
     * @param  list<string>  $values
     */
    private function checkIn(string $table, string $column, array $values, bool $nullable = false): void
    {
        $list = implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
        $null = $nullable ? "{$column} IS NULL OR " : '';

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$null}{$column} IN ({$list}))");
    }
};
