<?php

declare(strict_types=1);

use App\Enums\WalletTransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The wallet ledger: immutable financial history.
 *
 * Every movement of customer money is a row here, and `users.wallet_balance_toman`
 * is only the running total maintained alongside it. If the two ever disagree,
 * this table is right.
 *
 * Amounts are whole Toman in a BIGINT. Never a float: binary floating point
 * cannot represent these values exactly, and an accumulated rounding error in a
 * ledger is indistinguishable from theft.
 *
 * The arithmetic is checked by the database rather than trusted from the
 * application, because a ledger whose rows do not add up cannot be audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();

            // Restricted, never cascading: removing an account must not erase
            // the record of money that moved through it.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('type', 20);

            // Signed. Debits are stored negative so the ledger sums to the
            // balance without needing to know what each type means.
            $table->bigInteger('amount_toman');
            $table->bigInteger('balance_before_toman');
            $table->bigInteger('balance_after_toman');

            // A real constraint, not an application lookup: two concurrent
            // requests both check before either inserts.
            $table->string('idempotency_key')->unique();

            // What this movement was for. Deliberately not a foreign key: it
            // points at different tables, some of which arrive later.
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();

            $table->string('description');
            $table->jsonb('metadata')->nullable();

            // No updated_at. A row that can never change has no such time.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        $types = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            WalletTransactionType::values(),
        ));

        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_type_check CHECK (type IN ({$types}))");

        // A zero-value movement records nothing and would only obscure the log.
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_not_zero CHECK (amount_toman <> 0)');

        // The wallet may never go negative, before or after.
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_before_non_negative CHECK (balance_before_toman >= 0)');
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_after_non_negative CHECK (balance_after_toman >= 0)');

        // The row must add up. This is what makes the ledger auditable.
        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_arithmetic CHECK (balance_after_toman = balance_before_toman + amount_toman)');

        // Credits and refunds add; debits subtract. Only an administrative
        // adjustment is free to go either way.
        DB::statement(<<<'SQL'
            ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_sign_matches_type CHECK (
                (type IN ('credit', 'refund') AND amount_toman > 0)
                OR (type = 'debit' AND amount_toman < 0)
                OR (type = 'adjustment')
            )
        SQL);

        // The database is the final guard. An application that forgets the rule,
        // a future model that never learned it, or someone at a psql prompt all
        // meet the same refusal.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION wallet_transactions_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'wallet_transactions is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER wallet_transactions_no_update
                BEFORE UPDATE ON wallet_transactions
                FOR EACH ROW EXECUTE FUNCTION wallet_transactions_reject_mutation();

            CREATE TRIGGER wallet_transactions_no_delete
                BEFORE DELETE ON wallet_transactions
                FOR EACH ROW EXECUTE FUNCTION wallet_transactions_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        // Triggers drop with the table; the function is separate and must go
        // after it, or the drop would fail while the triggers still reference it.
        Schema::dropIfExists('wallet_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS wallet_transactions_reject_mutation()');
    }
};
