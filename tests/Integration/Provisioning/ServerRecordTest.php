<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Exceptions\FinancialRecordDeletionForbidden;
use App\Models\Server;
use App\Models\Subscription;
use App\Provisioning\Exceptions\ServerIdentityIsImmutable;
use App\Provisioning\ProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * What the servers table guarantees, whoever is writing to it.
 *
 * Reconciliation writes to this table constantly, from a provider's answers.
 * That makes it the one place where a third party's response could quietly
 * repoint a machine at a different customer or restate what they were charged,
 * so the identity and financial columns are refused by the model *and* by a
 * trigger — the trigger being what still holds for a query-builder call or a
 * psql prompt.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->server = Server::query()->firstOrFail();
});

function tamperedServerValue(string $column): mixed
{
    return match ($column) {
        'user_id', 'order_id', 'product_id', 'provider_id', 'provider_location_id' => 99,
        'selling_price_toman' => 1,
        'plan_snapshot', 'image_snapshot' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR),
        'billing_mode' => 'monthly',
        'provider_currency' => 'USD',
        'provisioning_uuid' => '00000000-0000-4000-8000-000000000000',
        'provider_cost' => '1.000000',
        'exchange_rate' => '1.00000000',
        'local_cost_toman' => '1.00000000000000',
        'gross_margin_toman' => '2.00000000000000',
        default => 'tampered',
    };
}

it('refuses to change which machine a record means, from raw sql', function (): void {
    foreach (Server::IMMUTABLE as $column) {
        if ($column === 'billing_mode') {
            // Only one value exists in Release 1.0, so there is nothing to
            // change it to; the trigger is proven by the other fifteen.
            continue;
        }

        // In a savepoint: PostgreSQL aborts a transaction on a constraint
        // error, which would take the rest of the loop with it.
        expect(fn () => DB::transaction(fn () => DB::table('servers')
            ->where('id', $this->server->id)
            ->update([$column => tamperedServerValue($column)])))
            ->toThrow(QueryException::class, '', $column);
    }
});

it('refuses the same changes through the model', function (): void {
    expect(fn () => $this->server->update(['user_id' => 99]))
        ->toThrow(ServerIdentityIsImmutable::class);

    expect(fn () => $this->server->update(['selling_price_toman' => 1]))
        ->toThrow(ServerIdentityIsImmutable::class);

    expect(fn () => $this->server->update(['provider_server_id' => 'somebody-elses-machine']))
        ->toThrow(ServerIdentityIsImmutable::class);
});

it('allows the operational fields a provider is entitled to correct', function (): void {
    $this->server->forceFill([
        'ip_address' => '203.0.113.10',
        'ipv6_address' => '2001:db8::10',
        'hostname' => 'web-1.example',
        'datacenter' => 'fsn1-dc14',
        'power_state' => App\Enums\ServerPowerState::Off,
        'status' => App\Enums\ServerStatus::Missing,
        'provider_metadata' => ['network_zone' => 'eu-central'],
        'suspended_at' => now(),
    ])->save();

    expect($this->server->fresh()->ip_address)->toBe('203.0.113.10')
        ->and($this->server->fresh()->hostname)->toBe('web-1.example');
});

it('keeps one server per order and one machine per record', function (): void {
    $second = $this->floor->paidOrder();

    expect(fn () => DB::transaction(fn () => DB::table('servers')->insert([
        'user_id' => $this->server->user_id,
        // The same order twice.
        'order_id' => $this->server->order_id,
        'product_id' => $this->server->product_id,
        'provider_id' => $this->server->provider_id,
        'provider_location_id' => $this->server->provider_location_id,
        'provider_server_id' => 'another-machine',
        'provisioning_uuid' => '00000000-0000-4000-8000-000000000001',
        'name' => 'dupe', 'plan_snapshot' => '{}', 'image_snapshot' => '{}',
        'status' => 'active', 'power_state' => 'on', 'billing_mode' => 'monthly',
        'provider_cost' => '1.0', 'provider_currency' => 'EUR', 'exchange_rate' => '1.0',
        'local_cost_toman' => '1.0', 'selling_price_toman' => 1, 'gross_margin_toman' => '1.0',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('servers')->insert([
        'user_id' => $this->server->user_id,
        'order_id' => $second->id,
        'product_id' => $this->server->product_id,
        'provider_id' => $this->server->provider_id,
        'provider_location_id' => $this->server->provider_location_id,
        // The same remote machine as an existing record.
        'provider_server_id' => $this->server->provider_server_id,
        'provisioning_uuid' => '00000000-0000-4000-8000-000000000002',
        'name' => 'dupe', 'plan_snapshot' => '{}', 'image_snapshot' => '{}',
        'status' => 'active', 'power_state' => 'on', 'billing_mode' => 'monthly',
        'provider_cost' => '1.0', 'provider_currency' => 'EUR', 'exchange_rate' => '1.0',
        'local_cost_toman' => '1.0', 'selling_price_toman' => 1, 'gross_margin_toman' => '1.0',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(Server::query()->count())->toBe(1);
});

it('keeps one subscription per server', function (): void {
    expect(fn () => DB::transaction(fn () => DB::table('subscriptions')->insert([
        'user_id' => $this->server->user_id,
        'server_id' => $this->server->id,
        'product_id' => $this->server->product_id,
        'status' => 'active',
        'current_period_start' => now(),
        'current_period_end' => now()->addDay(),
        'price_toman' => 1, 'billing_cycle' => 'monthly', 'billing_mode' => 'monthly',
        'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(Subscription::query()->count())->toBe(1);
});

it('retains servers, subscriptions and attempts rather than deleting them', function (): void {
    expect(fn () => $this->server->delete())->toThrow(FinancialRecordDeletionForbidden::class);
    expect(fn () => Subscription::query()->firstOrFail()->delete())
        ->toThrow(FinancialRecordDeletionForbidden::class);
    expect(fn () => App\Models\ProvisioningAttempt::query()->firstOrFail()->delete())
        ->toThrow(FinancialRecordDeletionForbidden::class);

    foreach (['servers', 'subscriptions', 'provisioning_attempts'] as $table) {
        expect(fn () => DB::transaction(fn () => DB::table($table)->delete()))
            ->toThrow(QueryException::class, '', $table);
    }

    expect(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(App\Models\ProvisioningAttempt::query()->count())->toBe(1);
});

it('uses no soft deletes to disguise a deletion', function (): void {
    foreach (['servers', 'subscriptions', 'provisioning_attempts'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeFalse("{$table}.deleted_at");
    }
});

it('has no second source of truth about expiry', function (): void {
    // The specification is explicit: current_period_end is the only one, and a
    // column here would eventually disagree with it.
    expect(Schema::hasColumn('servers', 'expires_at'))->toBeFalse()
        ->and(Schema::hasColumn('subscriptions', 'current_period_end'))->toBeTrue();
});

it('stores a root password encrypted, hidden, and nowhere else', function (): void {
    // Generated at runtime so the repository holds nothing credential-shaped.
    $marker = 'SYNTHETIC-'.bin2hex(random_bytes(8));

    $this->server->forceFill(['root_password_encrypted' => $marker])->save();
    $fresh = $this->server->fresh();

    // The application can read it back.
    expect($fresh->root_password_encrypted)->toBe($marker);

    // PostgreSQL cannot: the column holds ciphertext, so a dump, a backup and a
    // stray SELECT all show nothing.
    $raw = (string) DB::table('servers')->where('id', $this->server->id)->value('root_password_encrypted');

    expect($raw)->not->toBe($marker)
        ->and($raw)->not->toContain($marker)
        ->and($raw)->not->toBe('');

    // It is not in the serialized model.
    $encoded = json_encode($fresh->toArray(), JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain($marker)
        ->and($fresh->toArray())->not->toHaveKey('root_password_encrypted');

    // Nor anywhere it was never meant to be.
    expect(json_encode($fresh->provider_metadata ?? [], JSON_THROW_ON_ERROR))->not->toContain($marker)
        ->and(json_encode($fresh->plan_snapshot, JSON_THROW_ON_ERROR))->not->toContain($marker)
        ->and(json_encode($fresh->image_snapshot, JSON_THROW_ON_ERROR))->not->toContain($marker);

    foreach (['audit_logs', 'outbox_messages', 'provisioning_attempts'] as $table) {
        $rows = DB::table($table)->get()->map(
            fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR),
        )->implode(' ');

        expect($rows)->not->toContain($marker, $table);
    }
});

it('stores the root password the provider issued, encrypted', function (): void {
    // The simulator issues a one-time password on creation, as a
    // password-authenticating provider does. It has exactly one home, and the
    // provider is asked to confirm it rather than to hand it back: the provider
    // keeps only a one-way verifier, so there is nothing to hand back.
    $stored = $this->server->fresh()->root_password_encrypted;

    expect($stored)->not->toBeNull()
        ->and(Tests\Support\Provisioning\Simulator::plain()->credentialMatches(
            (string) $this->server->provider_server_id, $stored,
        ))->toBeTrue()
        // And the column holds ciphertext, not the password as typed.
        ->and(DB::table('servers')->where('id', $this->server->getKey())->value('root_password_encrypted'))
        ->not->toBe($stored);
});

it('keeps no provider credential in the metadata it stored', function (): void {
    $metadata = json_encode($this->server->provider_metadata ?? [], JSON_THROW_ON_ERROR);

    foreach (['password', 'token', 'secret', 'authorization', 'api_key'] as $forbidden) {
        expect(strtolower($metadata))->not->toContain($forbidden);
    }
});
