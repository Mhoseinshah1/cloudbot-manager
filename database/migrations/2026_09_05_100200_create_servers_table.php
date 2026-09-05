<?php

declare(strict_types=1);

use App\Enums\BillingMode;
use App\Enums\ServerPowerState;
use App\Enums\ServerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A server we sold, delivered and owe service on.
 *
 * Split the same way an order is: an identity half that is decided once, and an
 * operational half that a provider is allowed to move. Which machine this is,
 * whose it is, which order bought it and what the economics were are all fixed
 * at creation and frozen by a trigger. Where its IP is, whether it is powered
 * on, and whether we can still find it are expected to change every time
 * reconciliation runs.
 *
 * That split is the point. Reconciliation exists to make local records agree
 * with a provider, and a provider that answers strangely — a recycled id, a
 * response for the wrong account — must be able to correct an address and
 * never able to move a machine to a different customer or restate what it cost.
 *
 * The financial columns reproduce the order's snapshots exactly rather than
 * recomputing them. The customer was quoted those numbers; today's rate is a
 * different fact about a different day.
 *
 * There is no `expires_at`. Expiry lives in one place, on the subscription, and
 * a second column here would eventually disagree with it.
 */
return new class extends Migration
{
    /**
     * Which machine, whose, bought by which order, and what it cost.
     *
     * @var list<string>
     */
    private const IMMUTABLE = [
        'user_id', 'order_id', 'product_id', 'provider_id', 'provider_server_id',
        'provisioning_uuid', 'provider_location_id', 'plan_snapshot', 'image_snapshot',
        'billing_mode', 'provider_cost', 'provider_currency', 'exchange_rate',
        'local_cost_toman', 'selling_price_toman', 'gross_margin_toman',
    ];

    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table): void {
            $table->id();

            // Restricted throughout. A server outlives the catalog rows it was
            // built from: the product may be retired and the price row replaced,
            // and this record still has to say what was sold.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // One order buys exactly one server. Unique, so a second delivery
            // for the same purchase cannot be written however the code behaves.
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('provider_location_id')
                ->constrained('provider_locations')->restrictOnDelete();

            // The provider's own opaque identifier.
            $table->string('provider_server_id', 191);

            // The token this machine was created for. Copied here so a server
            // can be matched back to its intent without walking the order.
            $table->uuid('provisioning_uuid');

            $table->string('name', 191);
            $table->string('hostname', 191)->nullable();

            // Provider-synchronized. Nullable because a server can exist before
            // it has an address.
            $table->string('ip_address', 45)->nullable();
            $table->string('ipv6_address', 45)->nullable();
            $table->string('datacenter', 191)->nullable();

            // What was bought, as it stood. Enough for an operator to see the
            // machine's shape without trusting today's catalog.
            $table->jsonb('plan_snapshot');
            $table->jsonb('image_snapshot');

            // Whitelisted keys only, never a provider response.
            $table->jsonb('provider_metadata')->nullable();

            $table->string('status', 30)->default(ServerStatus::Active->value);
            $table->string('power_state', 20)->default(ServerPowerState::Unknown->value);
            $table->string('billing_mode', 20);

            // The provider's own price, at its own scale, exactly as quoted.
            $table->decimal('provider_cost', 20, 6);
            $table->string('provider_currency', 3);
            $table->decimal('exchange_rate', 20, 8);

            // NUMERIC(20,6) × NUMERIC(20,8) needs 14 + 12 = 26 integer digits
            // and exactly 6 + 8 = 14 fractional digits to be represented without
            // loss. NUMERIC(40,14) is that, with nothing to spare and nothing
            // rounded: these are derived money values and fractional Toman are
            // real here, whatever the column name suggests.
            $table->decimal('local_cost_toman', 40, 14);

            // What the customer pays. Whole Toman, BIGINT, PHP int.
            $table->bigInteger('selling_price_toman');

            // Selling price minus converted cost. Same scale as the cost it is
            // derived from; subtracting a BIGINT cannot widen it.
            $table->decimal('gross_margin_toman', 40, 14);

            // Encrypted by the model. Null when the provider issues none, which
            // is the honest value — never an invented placeholder.
            $table->text('root_password_encrypted')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('terminated_at')->nullable();

            $table->timestamps();

            // The specification's provider-server uniqueness invariant. Two
            // local records pointing at one remote machine is how a customer
            // ends up billed for someone else's server.
            $table->unique(['provider_id', 'provider_server_id']);

            // Reconciliation's lookups.
            $table->unique('provisioning_uuid');
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        $this->checkIn('servers', 'status', ServerStatus::values());
        $this->checkIn('servers', 'power_state', ServerPowerState::values());
        $this->checkIn('servers', 'billing_mode', BillingMode::values());

        DB::statement(
            'ALTER TABLE servers ADD CONSTRAINT servers_selling_price_positive CHECK (selling_price_toman > 0)'
        );
        DB::statement(
            'ALTER TABLE servers ADD CONSTRAINT servers_provider_cost_non_negative CHECK (provider_cost >= 0)'
        );
        DB::statement(
            'ALTER TABLE servers ADD CONSTRAINT servers_exchange_rate_positive CHECK (exchange_rate > 0)'
        );
        DB::statement(
            "ALTER TABLE servers ADD CONSTRAINT servers_provider_server_id_present CHECK (btrim(provider_server_id) <> '')"
        );

        // The identity and financial half, frozen in the database.
        //
        // The model refuses these too. This is the guard that still holds for a
        // reconciliation bug or a query builder call — the places where a
        // provider's answer could otherwise quietly repoint a row at a
        // different machine or restate what a customer was charged.
        $comparisons = implode("\n                OR ", array_map(
            static fn (string $column): string => "NEW.{$column} IS DISTINCT FROM OLD.{$column}",
            self::IMMUTABLE,
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION servers_reject_identity_change() RETURNS trigger AS $$
            BEGIN
                IF {$comparisons}
                THEN
                    RAISE EXCEPTION 'servers: which machine this is, and what it cost, cannot be changed'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER servers_no_identity_change
                BEFORE UPDATE ON servers
                FOR EACH ROW EXECUTE FUNCTION servers_reject_identity_change();
        SQL);

        // Retained. A terminated server becomes a historical record; it never
        // stops being one. Deleting the row would erase the evidence for every
        // invoice raised against it.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER servers_no_delete
                BEFORE DELETE ON servers
                FOR EACH ROW EXECUTE FUNCTION financial_records_reject_delete();
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasTable('servers')) {
            DB::unprepared('DROP TRIGGER IF EXISTS servers_no_delete ON servers');
            DB::unprepared('DROP TRIGGER IF EXISTS servers_no_identity_change ON servers');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS servers_reject_identity_change()');

        Schema::dropIfExists('servers');
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
