<?php

declare(strict_types=1);

use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ProvisioningOutcome;
use App\Enums\ProvisioningStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What we asked a provider to do, and what happened.
 *
 * Forensic history, not working state. Each row is one call, written before it
 * is made and closed when it returns, and then never rewritten: a reconciliation
 * that later finds the server does not go back and change the attempt that timed
 * out into a success. That attempt really did time out, and an operator asking
 * "what did we know at the time" needs the answer to still be there.
 *
 * The summaries are already-scrubbed facts assembled by the caller — the plan,
 * location and image asked for, the identity that came back. Never a provider
 * response, never an exception, and never a credential: the whole point of a
 * whitelist is that nothing arrives here by default.
 *
 * The provisioning token has its own column. It is a correlation identifier, not
 * an authentication secret — reconciliation looks servers up by it, and hiding
 * it from the one table an operator investigates would help nobody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_attempts', function (Blueprint $table): void {
            $table->id();

            // Restricted: an order cannot be deleted anyway, and an attempt is
            // retained history in its own right.
            $table->foreignId('order_id')->constrained()->restrictOnDelete();

            // Copied, not derived through the order, so the history stays
            // readable as a sequence of calls carrying one token.
            $table->uuid('provisioning_uuid');

            $table->unsignedInteger('attempt_no');

            $table->string('stage', 30);
            $table->string('outcome', 40);
            $table->string('error_category', 40)->nullable();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // Whitelisted facts only. The request we composed, and the identity
            // that came back.
            $table->jsonb('request_summary');
            $table->jsonb('result_summary')->nullable();

            $table->timestamps();

            // One row per attempt number per order, enforced by the database.
            // Two workers numbering attempts by counting would otherwise both
            // read "2 so far" and both write attempt 3.
            $table->unique(['order_id', 'attempt_no']);

            // Reconciliation's lookup: everything ever tried for this token.
            $table->index('provisioning_uuid');
            $table->index(['outcome', 'created_at']);
        });

        $this->checkIn('provisioning_attempts', 'stage', ProvisioningStage::values());
        $this->checkIn('provisioning_attempts', 'outcome', ProvisioningOutcome::values());
        $this->checkIn(
            'provisioning_attempts', 'error_category', ProviderErrorCategory::values(), nullable: true,
        );

        // Attempt numbers start at one. A zeroth attempt is a bug in whatever
        // counted, and letting it in makes the sequence unreadable.
        DB::statement(
            'ALTER TABLE provisioning_attempts ADD CONSTRAINT provisioning_attempts_attempt_no_positive
             CHECK (attempt_no > 0)'
        );

        // Retained, by the same guard payments, invoices and orders already use.
        // An attempt is the evidence for a refund decision; deleting it would
        // erase why a customer was or was not given their money back.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER provisioning_attempts_no_delete
                BEFORE DELETE ON provisioning_attempts
                FOR EACH ROW EXECUTE FUNCTION financial_records_reject_delete();
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('provisioning_attempts')) {
            DB::unprepared('DROP TRIGGER IF EXISTS provisioning_attempts_no_delete ON provisioning_attempts');
        }

        Schema::dropIfExists('provisioning_attempts');
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
