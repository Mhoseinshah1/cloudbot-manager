<?php

declare(strict_types=1);

use App\Enums\InvoiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Now that orders exist, say so in the two tables that were waiting for them.
 *
 * `payments.order_id` and `invoices.order_id` were created as plain nullable
 * columns because inventing an orders table to satisfy a constraint would have
 * been worse than waiting. This adds the real foreign keys.
 *
 * Both stay nullable. A wallet top-up is a payment with no order behind it, and
 * that is the normal case for Release 1.0, not an omission.
 *
 * Also widens the invoice type constraint for the purchase invoice a paid order
 * produces. The constraint is rebuilt rather than edited because PostgreSQL has
 * no ALTER for a CHECK body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // Restricted: an order is the reason a payment exists, and losing
            // the order would leave money with no explanation.
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });

        $types = implode(', ', array_map(static fn (string $v): string => "'{$v}'", InvoiceType::values()));

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_type_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (type IN ({$types}))");
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        // Back to the set that existed before this migration ran.
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_type_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (type IN ('wallet_top_up'))");
    }
};
