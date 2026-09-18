<?php

declare(strict_types=1);

use App\Enums\InvoiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let an invoice say it paid for a renewal.
 *
 * The invoice type is constrained in the database, not only in the enum, so a
 * new kind of document needs the constraint widened before one can be written.
 * A renewal is a different thing from the original purchase — it buys one more
 * period of a service that already exists — and recording it as a
 * `server_purchase` would make the customer's invoice history claim they bought
 * the same machine twice.
 *
 * Renewal invoices carry no `order_id`: they belong to a subscription, and the
 * order that created it was paid for long ago.
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            InvoiceType::values(),
        ));

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_type_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (type IN ({$types}))");
    }

    public function down(): void
    {
        // Back to the types that existed before renewals. Rows already written
        // as renewals would violate it, so the constraint is only narrowed when
        // none remain — a rollback with live renewal invoices is a data
        // question, not a schema one, and failing loudly is the honest outcome.
        $types = implode(', ', array_map(
            static fn (InvoiceType $type): string => "'{$type->value}'",
            [InvoiceType::WalletTopUp, InvoiceType::ServerPurchase],
        ));

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_type_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (type IN ({$types}))");
    }
};
