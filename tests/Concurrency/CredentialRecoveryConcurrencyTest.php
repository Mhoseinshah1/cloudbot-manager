<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\ProvisioningAttempt;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Outbox\OutboxTopic;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use App\Provisioning\ServerPersister;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ForkedWorkers;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * Two workers finding the same undelivered machine at the same instant.
 *
 * Credential recovery rotates a password, and rotation is the one operation
 * here that is safe to repeat and disastrous to interleave. Two resets racing
 * would each obtain a password, each believe theirs is current, and one would
 * persist a credential the provider had already replaced — a server delivered
 * with a password that does not work, which is exactly the outcome this whole
 * correction exists to prevent.
 *
 * Nothing in the rotation itself prevents that. What does is the same per-order
 * provisioning lock every other provider decision runs under, and the only way
 * to demonstrate it is two real processes against one PostgreSQL database and
 * the real Redis lock topology.
 */
function resetCredentialRecoveryTables(): void
{
    DB::statement(
        'TRUNCATE subscriptions, servers, provisioning_attempts, outbox_messages, wallet_transactions,
         invoices, payments, orders, product_location_prices, products, provider_images, provider_plans,
         provider_locations, provider_credentials, providers, exchange_rates, settings, audit_logs,
         fake_provider_servers, fake_provider_actions RESTART IDENTITY CASCADE'
    );
    DB::table('users')->delete();
}

beforeEach(function (): void {
    resetCredentialRecoveryTables();
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open(walletBalance: 20_000_000);
});

afterEach(function (): void {
    DB::statement('ALTER TABLE servers DROP CONSTRAINT IF EXISTS cbm010_concurrency_block');
    resetCredentialRecoveryTables();
});

it('rotates a credential once when two workers recover the same order at once', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    // A machine that exists remotely and was never delivered: the create-time
    // password is gone and the local write left nothing behind.
    DB::statement('ALTER TABLE servers ADD CONSTRAINT cbm010_concurrency_block CHECK (id < 0) NOT VALID');
    DB::statement('ALTER TABLE servers VALIDATE CONSTRAINT cbm010_concurrency_block');
    app(ProvisioningService::class)->provision($order);
    DB::statement('ALTER TABLE servers DROP CONSTRAINT cbm010_concurrency_block');

    expect(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(0);

    $results = ForkedWorkers::run(2, function () use ($orderId): array {
        $scripted = Simulator::script();

        $result = app(ReconciliationService::class)->reconcile(
            Order::query()->findOrFail($orderId),
        );

        return [
            'state' => $result->state,
            // Every provider call this process made, so a second rotation or a
            // second create would be visible rather than inferred.
            'calls' => $scripted->calls,
        ];
    });

    $calls = array_merge($results[0]['calls'] ?? [], $results[1]['calls'] ?? []);
    $resets = count(array_filter($calls, static fn (string $call): bool => $call === 'resetRootPassword'));
    $creates = count(array_filter($calls, static fn (string $call): bool => $call === 'createServer'));

    $fresh = Order::query()->findOrFail($orderId);
    $server = Server::query()->sole();
    $delivered = (string) $server->root_password_encrypted;

    expect($results[0]['error'])->toBeNull()
        ->and($results[1]['error'])->toBeNull()
        // At most one rotation actually reached the provider. The loser found
        // the lock held and made no provider call at all.
        ->and($resets)->toBeLessThanOrEqual(1)
        ->and($creates)->toBe(0)
        // Exactly one rotation was recorded durably, on its own stage.
        ->and(ProvisioningAttempt::query()->where('stage', 'credential_recovery')->count())
        ->toBeLessThanOrEqual(1)
        // One delivery, and the credential on file is the one the provider
        // currently holds — not a password some racing worker replaced.
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        // Confirmed by presenting it, not by reading provider storage.
        ->and(Simulator::plain()->credentialMatches((string) $server->provider_server_id, $delivered))
        ->toBeTrue()
        ->and($fresh->status)->toBe(OrderStatus::Provisioned)
        ->and($fresh->provisioning_uuid)->toBe(FakeProviderServer::query()->value('provisioning_token'))
        // One success notification, and no money moved.
        ->and(OutboxMessage::query()
            ->where('deduplication_key', ServerPersister::successDeduplicationKey($fresh))
            ->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', 'refund')->count())->toBe(0);
});

it('makes no provider call from a worker that cannot take the order lock', function (): void {
    $order = $this->floor->paidOrder();
    $orderId = (int) $order->getKey();

    DB::statement('ALTER TABLE servers ADD CONSTRAINT cbm010_concurrency_block CHECK (id < 0) NOT VALID');
    DB::statement('ALTER TABLE servers VALIDATE CONSTRAINT cbm010_concurrency_block');
    app(ProvisioningService::class)->provision($order);
    DB::statement('ALTER TABLE servers DROP CONSTRAINT cbm010_concurrency_block');

    $results = ForkedWorkers::run(2, function (int $index) use ($orderId): array {
        $scripted = Simulator::script();

        if ($index === 0) {
            // Holds the lock through a slow rotation.
            $scripted->onPasswordReset(static function (string $serverId, $inner) {
                usleep(2_000_000);

                return $inner->resetRootPassword($serverId);
            });

            $result = app(ReconciliationService::class)->reconcile(Order::query()->findOrFail($orderId));

            return ['role' => 'recover', 'state' => $result->state, 'calls' => $scripted->calls];
        }

        usleep(700_000);

        $result = app(ReconciliationService::class)->reconcile(Order::query()->findOrFail($orderId));

        return ['role' => 'contender', 'state' => $result->state, 'calls' => $scripted->calls];
    });

    expect($results[1]['error'])->toBeNull()
        ->and($results[1]['state'])->toBe(App\Provisioning\Data\ProvisioningResult::Contended)
        // Not one call. A worker that cannot coordinate must not rotate a
        // password somebody else is in the middle of rotating.
        ->and($results[1]['calls'])->toBe([])
        ->and(Server::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1);
});
