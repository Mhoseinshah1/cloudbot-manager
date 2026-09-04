<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Provisioning\ProvisioningService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * The provisioning token, which is the whole reason one paid order cannot
 * become two servers.
 *
 * Two properties have to hold, and neither is provable by reading the code.
 * The token must be durable *before* the provider is asked to do anything, so
 * that a worker dying mid-call leaves a record of what it was about to do; and
 * once assigned it must never change, because a provider answers a repeat by
 * returning what that token already built.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->provisioning = app(ProvisioningService::class);
});

it('opens no database transaction of its own around any provider call', function (): void {
    // RefreshDatabase wraps this test in a transaction, so the baseline is not
    // zero. What matters is that production code adds nothing to it: a provider
    // call held inside its own transaction would sit on row locks across
    // somebody else's network. The proof that the token is genuinely *committed*
    // before the call needs real commits and lives in the Concurrency suite.
    $baseline = DB::transactionLevel();
    $levels = [];

    $scripted = Simulator::script();
    $scripted->onAvailability(function () use (&$levels): bool {
        $levels['checkAvailability'] = DB::transactionLevel();

        return true;
    });
    $scripted->beforeCreate(function () use (&$levels): void {
        $levels['createServer'] = DB::transactionLevel();
    });
    $scripted->onListServers(function (array $servers) use (&$levels): array {
        $levels['listServers'] = DB::transactionLevel();

        return $servers;
    });

    $this->provisioning->provision($this->floor->paidOrder());

    expect($levels)->toHaveKeys(['listServers', 'checkAvailability', 'createServer'])
        ->and(array_values($levels))->toBe([$baseline, $baseline, $baseline]);
});

it('hands the provider the token the order already carries', function (): void {
    $order = $this->floor->paidOrder();
    $seen = [];

    $scripted = Simulator::script();
    $scripted->beforeCreate(function (CreateServerRequest $request) use ($order, &$seen): void {
        // Read back through the application's own connection: this shows the
        // token was written before the call, though not that it was committed.
        $seen['persisted'] = Order::query()->whereKey($order->getKey())->value('provisioning_uuid');
        $seen['status'] = Order::query()->whereKey($order->getKey())->value('status');
        $seen['sent'] = $request->provisioningToken;
    });

    $this->provisioning->provision($order);

    // value() still applies the model's enum cast, so this is an OrderStatus.
    expect($seen['status'])->toBe(OrderStatus::Provisioning)
        ->and($seen['persisted'])->not->toBeNull()
        ->and($seen['sent'])->toBe($seen['persisted'])
        ->and($seen['sent'])->toBe($order->fresh()->provisioning_uuid);
});

it('assigns a token once and keeps it through every retry', function (): void {
    $order = $this->floor->paidOrder();

    $prepared = $this->provisioning->prepare($order);
    $token = $prepared->provisioning_uuid;

    expect($token)->not->toBeNull()
        ->and($prepared->status)->toBe(OrderStatus::Provisioning);

    // Prepared again, as a duplicated job or a resumed worker would.
    foreach (range(1, 3) as $ignored) {
        $again = $this->provisioning->prepare($order->fresh());

        expect($again->provisioning_uuid)->toBe($token);
    }
});

it('refuses to change an assigned token, even from raw SQL', function (): void {
    $order = $this->floor->paidOrder();
    $token = $this->provisioning->prepare($order)->provisioning_uuid;

    // The realistic version of this is an operator clearing a "stuck" order's
    // uuid so it will retry. That would ask the provider for a second machine.
    // In a savepoint: PostgreSQL aborts a transaction outright on a constraint
    // error, which would take the rest of the test with it.
    expect(fn () => DB::transaction(fn () => DB::table('orders')
        ->where('id', $order->getKey())
        ->update(['provisioning_uuid' => (string) Str::uuid()])))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('orders')
        ->where('id', $order->getKey())
        ->update(['provisioning_uuid' => null])))
        ->toThrow(QueryException::class);

    expect($order->fresh()->provisioning_uuid)->toBe($token);
});

it('allows a token to be written and rewritten with the same value', function (): void {
    $order = $this->floor->paidOrder();
    $token = $this->provisioning->prepare($order)->provisioning_uuid;

    // Idempotent writes are fine; only a *different* value is refused.
    DB::table('orders')->where('id', $order->getKey())->update(['provisioning_uuid' => $token]);

    expect($order->fresh()->provisioning_uuid)->toBe($token);
});

it('keeps the same token after a failure and never issues a replacement', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->rejectCreate(App\Cloud\Enums\ProviderErrorCategory::TransientProviderError, 'Try later.');

    $this->provisioning->provision($order);
    $afterFailure = $order->fresh()->provisioning_uuid;

    expect($afterFailure)->not->toBeNull();

    // The provider recovers; the same token must be used.
    $scripted->beforeCreate(fn () => null);
    $this->provisioning->provision($order->fresh());

    expect($order->fresh()->provisioning_uuid)->toBe($afterFailure)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->firstOrFail()->provisioning_token)->toBe($afterFailure);
});

it('sends the committed token on every create it makes', function (): void {
    $order = $this->floor->paidOrder();
    $sent = [];

    $scripted = Simulator::script();
    $scripted->beforeCreate(function (CreateServerRequest $request) use (&$sent): void {
        $sent[] = $request->provisioningToken;
    });

    $this->provisioning->provision($order);
    $token = $order->fresh()->provisioning_uuid;

    expect($sent)->not->toBeEmpty()
        ->and(array_unique($sent))->toBe([$token]);
});

it('keeps orders.provisioning_uuid unique across the whole table', function (): void {
    $first = $this->floor->paidOrder();
    $second = $this->floor->paidOrder();

    $token = $this->provisioning->prepare($first)->provisioning_uuid;

    // Two orders meaning one intended machine is the failure the unique index
    // exists to make impossible.
    expect(fn () => DB::transaction(fn () => DB::table('orders')
        ->where('id', $second->getKey())
        ->update(['provisioning_uuid' => $token])))
        ->toThrow(QueryException::class);
});
