<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of security- and money-sensitive actions.
 *
 * Append-only is enforced twice. The model refuses updates and deletes, and the
 * database refuses them too, because an audit trail that application code can
 * quietly rewrite is not evidence of anything. The database guard is the one
 * that still holds when someone reaches the table through psql or a future
 * model that forgets the rule.
 *
 * There is no `updated_at`: a row that can never be updated has no such time,
 * and carrying the column would imply otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            $table->string('event');

            // Polymorphic without a relation constraint: the actor may be a
            // user, a console command or the scheduler, and the subject may be
            // a record that is later removed from its own table.
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            // Scrubbed before insert: no credentials, tokens or passwords.
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('event');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('created_at');
        });

        // Raising inside a BEFORE trigger aborts the statement, so an UPDATE or
        // DELETE fails rather than silently affecting zero rows.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation();

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation();
        SQL);
    }

    public function down(): void
    {
        // Triggers go with the table; the function is shared by neither.
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_reject_mutation()');
    }
};
