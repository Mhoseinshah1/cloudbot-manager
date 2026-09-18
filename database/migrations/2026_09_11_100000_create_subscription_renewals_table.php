<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per service period a customer actually paid to extend.
 *
 * The table exists for one reason: a renewal has to have a single durable
 * identity that the database can refuse to duplicate. Without it, "was this
 * period already renewed?" is a question answered by inspecting a wallet
 * transaction, an invoice and a subscription and hoping the three agree — and
 * two confirmations arriving together would each look at a subscription that
 * had not moved yet and both charge for the same thirty days.
 *
 * `idempotency_key` carries the subscription and the period end being replaced,
 * so a retry, a double tap and a re-delivered callback all collide on the same
 * unique index and exactly one of them wins. After a successful renewal the
 * period end has moved, so the next legitimate renewal has a different key.
 *
 * Append-only, like the rest of the financial record: it says money moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_renewals', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();

            // The period this renewal replaced, and the one it bought. Both are
            // kept because the pair is the whole account of what was sold.
            $table->timestamp('old_period_end');
            $table->timestamp('new_period_end');

            // Whole Toman, never a float.
            $table->bigInteger('amount_toman');

            // What the database refuses to have twice.
            $table->string('idempotency_key')->unique();

            $table->timestamps();

            $table->index(['subscription_id', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION subscription_renewals_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'subscription_renewals is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER subscription_renewals_no_update
                BEFORE UPDATE ON subscription_renewals
                FOR EACH ROW EXECUTE FUNCTION subscription_renewals_reject_mutation();

            CREATE TRIGGER subscription_renewals_no_delete
                BEFORE DELETE ON subscription_renewals
                FOR EACH ROW EXECUTE FUNCTION subscription_renewals_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_renewals');
        DB::unprepared('DROP FUNCTION IF EXISTS subscription_renewals_reject_mutation()');
    }
};
