<?php

declare(strict_types=1);

use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every operation a person asked us to perform on a server.
 *
 * Forensic history first and a work queue second. When a customer says their
 * server rebooted twice, or an operator finds a machine gone that nobody
 * admits deleting, this table is the only account of who asked for what and
 * what the provider said — so rows are never deleted and the facts of identity
 * are never rewritten.
 *
 * `idempotency_key` is the part that keeps a duplicate request from becoming a
 * duplicate reboot. Telegram re-delivers, queues re-deliver, and a customer
 * whose button did not visibly respond presses it again; the unique index means
 * all of those resolve to one row, and one row is one remote operation.
 *
 * The lifecycle columns move — a pending action becomes running, then settles —
 * but which server, which customer and which action never do.
 */
return new class extends Migration
{
    /**
     * Who asked, of what, for what. None of it is ever corrected.
     *
     * @var list<string>
     */
    private const IMMUTABLE = ['server_id', 'actor_type', 'actor_id', 'action', 'idempotency_key'];

    public function up(): void
    {
        Schema::create('server_actions', function (Blueprint $table): void {
            $table->id();

            // Restricted: the server row is retained history, and this is part
            // of the account of what happened to it.
            $table->foreignId('server_id')->constrained()->restrictOnDelete();

            // Who asked. Polymorphic in shape but stored as plain columns, so
            // a customer, an operator and a scheduled sweep are all recordable
            // without one of them needing a table.
            $table->string('actor_type', 60);
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('action', 40);
            $table->string('status', 30)->default(ServerActionStatus::Pending->value);

            // What makes a repeated request one operation. Derived from the
            // business intent — the Telegram interaction, the delete intent —
            // never from callback data, which a customer controls.
            $table->string('idempotency_key')->unique();

            // The provider's own handle for the work, when it gave one. Only
            // an identifier: no response body, ever.
            $table->string('provider_action_id', 120)->nullable();

            // Normalized. A provider's own error text quotes back the request,
            // and the request carries credentials.
            $table->string('error_category', 40)->nullable();

            // Facts we chose to keep. Never a password, never a payload.
            $table->jsonb('metadata')->nullable();

            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('settled_at')->nullable();

            // How many times a worker has actually reached for the provider.
            // A destructive action must not be retried forever, and a count is
            // what lets a bound exist at all.
            $table->unsignedInteger('attempts')->default(0);

            $table->timestamps();

            // The customer's history for one server, newest first.
            $table->index(['server_id', 'created_at']);
            // The reconciler's query: everything not yet settled.
            $table->index(['status', 'created_at']);
            $table->index('provider_action_id');
        });

        foreach ([
            'server_actions_action_known' => "action IN ('".implode("','", ServerActionType::values())."')",
            'server_actions_status_known' => "status IN ('".implode("','", ServerActionStatus::values())."')",
            'server_actions_error_category_known' => "error_category IS NULL OR error_category IN ('".implode("','", ProviderErrorCategory::values())."')",
            // A settled action has a time; an open one does not claim to.
            'server_actions_settled_at_matches_status' => "(status IN ('succeeded', 'failed')) = (settled_at IS NOT NULL)",
            'server_actions_attempts_non_negative' => 'attempts >= 0',
        ] as $name => $expression) {
            DB::statement("ALTER TABLE server_actions ADD CONSTRAINT {$name} CHECK ({$expression})");
        }

        // Retained, like every other record of something that happened to a
        // customer's money or machine. The model refuses deletion too; this is
        // the guard that still holds at a psql prompt, which is where an
        // accidental DELETE with a wrong WHERE actually happens.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION server_actions_reject_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'server_actions is retained operational history: DELETE is not permitted'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER server_actions_no_delete
                BEFORE DELETE ON server_actions
                FOR EACH ROW EXECUTE FUNCTION server_actions_reject_delete();
        SQL);

        $guards = implode("\n", array_map(
            static fn (string $column): string => <<<SQL
                    IF NEW.{$column} IS DISTINCT FROM OLD.{$column} THEN
                        RAISE EXCEPTION 'server_actions.{$column} is fixed when the action is requested'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
            SQL,
            self::IMMUTABLE,
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION server_actions_protect_identity() RETURNS trigger AS $$
            BEGIN
            {$guards}
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER server_actions_identity_is_immutable
                BEFORE UPDATE ON server_actions
                FOR EACH ROW EXECUTE FUNCTION server_actions_protect_identity();
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('server_actions')) {
            DB::unprepared('DROP TRIGGER IF EXISTS server_actions_no_delete ON server_actions');
            DB::unprepared('DROP TRIGGER IF EXISTS server_actions_identity_is_immutable ON server_actions');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS server_actions_reject_delete()');
        DB::unprepared('DROP FUNCTION IF EXISTS server_actions_protect_identity()');

        Schema::dropIfExists('server_actions');
    }
};
