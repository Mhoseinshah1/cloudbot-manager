<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A record of what a customer was charged and for what.
 *
 * Amounts are whole Toman in a BIGINT, matching the ledger.
 *
 * `issued_at` is stored in UTC like every timestamp in this system. Rendering it
 * in Jalali for a customer is presentation, done on the way out, never storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // As with payments: a plain column until orders exist.
            $table->unsignedBigInteger('order_id')->nullable();

            // Customer-facing identity, and the thing that makes issuing an
            // invoice twice for one payment impossible.
            $table->string('number', 50)->unique();

            $table->string('type', 30);
            $table->bigInteger('amount_toman');
            $table->string('status', 20)->default(InvoiceStatus::Issued->value);

            $table->timestamp('issued_at');

            // What the customer is being charged for. Descriptions and amounts,
            // never anything from a receipt or a credential.
            $table->jsonb('line_items');

            // The prices and exchange rate in force when this was issued, so a
            // later rate change cannot rewrite history. Empty for a pure Toman
            // wallet top-up, which involves no conversion at all.
            $table->jsonb('pricing_snapshot')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'issued_at']);
        });

        $types = implode(', ', array_map(static fn (string $v): string => "'{$v}'", InvoiceType::values()));
        $statuses = implode(', ', array_map(static fn (string $v): string => "'{$v}'", InvoiceStatus::values()));

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amount_positive CHECK (amount_toman > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
