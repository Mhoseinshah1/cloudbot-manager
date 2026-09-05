<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Fake\Models\FakeProviderAction;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Jobs\ExecuteServerActionJob;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\ServerAction;
use App\Provisioning\ProvisioningService;
use App\Servers\ServerActionLock;
use App\Servers\ServerActionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ForkedWorkers;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * RCH-006, with the coordination lock deliberately taken away.
 *
 * The per-server lock is not a correctness mechanism and this is the test that
 * says so out loud. It is a Redis key with a TTL: it expires while a provider
 * call is still outstanding, it disappears when Redis does, and a stalled worker
 * has no way to keep holding it. Every argument that ends "…but the lock
 * prevents that" is an argument that stops being true the moment the key is
 * gone.
 *
 * So here it is gone, on purpose, while a real worker is inside a real
 * deleteServer() in another process. A second worker then runs the ordinary
 * execution job, genuinely acquires the free lock, and reaches PostgreSQL — and
 * PostgreSQL is what refuses it, which is the only place that refusal can
 * safely live.
 *
 * The provider write count is read from `fake_provider_actions`, which is
 * shared, committed state rather than a counter inside one process. A second
 * delete would be a row, and rows survive the process that wrote them.
 */
function resetServerActionRaceTables(): void
{
    DB::statement(
        'TRUNCATE subscriptions, servers, server_actions, provisioning_attempts, outbox_messages,
         wallet_transactions, invoices, payments, orders, product_location_prices, products,
         provider_images, provider_plans, provider_locations, provider_credentials, providers,
         exchange_rates, settings, audit_logs, notification_logs, fake_provider_servers,
         fake_provider_actions RESTART IDENTITY CASCADE'
    );
    DB::table('users')->delete();
}

beforeEach(function (): void {
    resetServerActionRaceTables();
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    app(ProvisioningService::class)->provision($this->floor->paidOrder());

    $this->server = Server::query()->sole();
    $this->actions = app(ServerActionService::class);

    // The coordination key must start free; a leftover from an earlier test
    // would prove nothing.
    Cache::store('locks')->lock(ServerActionLock::keyFor($this->server), 1)->forceRelease();
});

afterEach(function (): void {
    resetServerActionRaceTables();
});

/** An accepted action whose delivery has not yet been performed. */
function raceAction(ServerActionType $type, string $key): ServerAction
{
    $action = test()->actions->request(test()->floor->customer, test()->server->getKey(), $type, $key);

    OutboxMessage::query()
        ->where('deduplication_key', ServerActionService::requestKey($action))
        ->update(['processed_at' => now()]);

    return $action->fresh();
}

/** Provider WRITE attempts of one kind, as committed rows rather than a counter. */
function providerWrites(string $command): int
{
    return FakeProviderAction::query()->where('command', $command)->count();
}

it('sends one delete when the coordination lock is lost mid-call', function (): void {
    $actionId = (int) raceAction(ServerActionType::Delete, 'race-lock-loss')->getKey();
    $serverId = (int) $this->server->getKey();
    $lockKey = ServerActionLock::keyFor($this->server);

    $results = ForkedWorkers::run(2, function (int $index) use ($actionId, $serverId, $lockKey): array {
        if ($index === 0) {
            // Worker A. The ordinary job, against a provider whose delete takes
            // a while to come back — which is the normal case this whole design
            // exists for, not an exotic one.
            Simulator::script()->onOperation('deleteServer', static function (): null {
                usleep(1_800_000);

                return null;
            });

            app()->call([new ExecuteServerActionJob($actionId), 'handle']);

            return ['role' => 'worker-a', 'finished_at' => microtime(true)];
        }

        // Worker B, arriving while Worker A is still inside the provider call.
        usleep(600_000);

        // The coordination key is removed exactly as a TTL expiry or a Redis
        // restart would remove it. Worker A does not know and cannot know.
        Cache::store('locks')->lock($lockKey, 60)->forceRelease();

        $before = providerWrites('delete');

        app()->call([new ExecuteServerActionJob($actionId), 'handle']);

        $after = providerWrites('delete');

        // Positive evidence that Redis was not what stopped it: the key really
        // was free in this window, so Worker B's job really did get inside.
        $probe = app(ServerActionLock::class)->attempt(
            Server::query()->findOrFail($serverId), static fn (): string => 'entered',
        );

        return [
            'role' => 'worker-b',
            'writes_before' => $before,
            'writes_added' => $after - $before,
            'attempts_seen' => (int) ServerAction::query()->whereKey($actionId)->value('attempts'),
            'lock_was_free' => $probe === 'entered',
            'finished_at' => microtime(true),
        ];
    });

    [$workerA, $workerB] = $results;

    expect($workerA['error'])->toBeNull()
        ->and($workerB['error'])->toBeNull()
        // Worker B genuinely got past the coordination layer. Without this the
        // test would pass for the wrong reason.
        ->and($workerB['lock_was_free'])->toBeTrue()
        // Worker A had reserved the attempt before its call, and Worker B saw
        // exactly the shape the finding is about.
        ->and(['writes_added' => $workerB['writes_added'], 'attempts' => $workerB['attempts_seen']])
        ->toBe(['writes_added' => 0, 'attempts' => 1])
        // Worker A ran long, so it finished last.
        ->and($workerA['finished_at'])->toBeGreaterThan($workerB['finished_at']);

    // One machine, deleted once, by one attempt.
    expect(providerWrites('delete'))->toBe(1)
        ->and((int) ServerAction::query()->whereKey($actionId)->value('attempts'))->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(ServerAction::query()->findOrFail($actionId)->status)
        ->toBe(ServerActionStatus::Succeeded);
});

it('sends one reboot when two workers race from a never-attempted action', function (): void {
    // The ordinary duplicate delivery, with no lock tampering: two processes
    // reading the same untouched row at the same instant. One reservation wins.
    $actionId = (int) raceAction(ServerActionType::Reboot, 'race-duplicate-delivery')->getKey();

    $results = ForkedWorkers::run(2, function () use ($actionId): array {
        Simulator::script()->onOperation('reboot', static function (): null {
            usleep(400_000);

            return null;
        });

        app()->call([new ExecuteServerActionJob($actionId), 'handle']);

        return ['writes' => providerWrites('reboot')];
    });

    expect($results[0]['error'])->toBeNull()
        ->and($results[1]['error'])->toBeNull()
        ->and(providerWrites('reboot'))->toBe(1)
        ->and((int) ServerAction::query()->whereKey($actionId)->value('attempts'))->toBe(1);
});

it('refuses a second reservation from a separate connection', function (): void {
    // The narrowest possible statement of the rule, with no executor, no job
    // and no lock anywhere near it: two processes, one row, one reservation.
    $actionId = (int) raceAction(ServerActionType::PowerOff, 'race-reservation-only')->getKey();
    $maximum = max(1, (int) config('cloudbot.server_actions.max_attempts', 3));

    $results = ForkedWorkers::run(2, function (int $index) use ($actionId, $maximum): array {
        if ($index === 1) {
            // Far enough behind that the first reservation has committed, so
            // this is not a tie — it is a second worker reading a reserved row.
            usleep(500_000);
        }

        $action = ServerAction::query()->findOrFail($actionId);

        return [
            'reserved' => app(ServerActionService::class)->reserveAttempt($action, $maximum),
        ];
    });

    expect($results[0]['error'])->toBeNull()
        ->and($results[1]['error'])->toBeNull()
        ->and($results[0]['reserved'])->toBeTrue()
        ->and($results[1]['reserved'])->toBeFalse()
        ->and((int) ServerAction::query()->whereKey($actionId)->value('attempts'))->toBe(1);
});
