<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Enums\ProvisioningStage;
use App\Jobs\ProvisionOrderJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\ProvisioningAttempt;
use App\Models\Server;
use App\Models\Subscription;
use App\Provisioning\CreateBudget;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use App\Support\Queues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\Simulator;

/**
 * The sweeper repairing work that was lost, rather than describing it.
 *
 * The specification says the sweep protects against a worker crash and a lost
 * queue retry. Neither is protected against by returning the word "retryable":
 * something has to put the work back on the queue, or a paid order sits
 * untouched forever with a customer's money and no server.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->provisioning = app(ProvisioningService::class);
    $this->reconciliation = app(ReconciliationService::class);

    foreach (Queues::names() as $queue) {
        Queue::clear($queue);
    }
});

/** An order claimed and tokened, whose provisioning job never arrived. */
function lostDelivery(): Order
{
    $order = test()->floor->paidOrder();
    test()->provisioning->prepare($order);

    // Old enough for the sweep to notice.
    DB::table('orders')->where('id', $order->getKey())
        ->update(['updated_at' => CarbonImmutable::now()->subHour()]);

    return $order->fresh();
}

it('queues provisioning again when the sweep finds a lost delivery', function (): void {
    $order = lostDelivery();
    $token = $order->provisioning_uuid;

    expect($token)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Provisioning)
        // Nothing was ever built, and no job is waiting to build it.
        ->and(FakeProviderServer::query()->count())->toBe(0)
        ->and(Queue::size(Queues::Provisioning->value))->toBe(0);

    $this->artisan('provisioning:reconcile')->assertExitCode(0);

    // The repair: work is back on the dedicated queue.
    expect(Queue::size(Queues::Provisioning->value))->toBe(1)
        ->and(Queue::size(Queues::Default->value))->toBe(0)
        ->and(Queue::size(Queues::Telegram->value))->toBe(0);

    // Running that job finishes the order, under the original token.
    Simulator::script();
    (new ProvisionOrderJob((int) $order->getKey()))->handle($this->provisioning);

    $fresh = $order->fresh();

    expect($fresh->status)->toBe(OrderStatus::Provisioned)
        ->and($fresh->provisioning_uuid)->toBe($token)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->firstOrFail()->provisioning_token)->toBe($token)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        // Phase 6 invoiced the purchase; delivery does not invoice it again.
        ->and(Invoice::query()->where('order_id', $order->getKey())->count())->toBe(1);

    Queue::clear(Queues::Provisioning->value);
});

it('queues provisioning again for an order named by hand', function (): void {
    $order = lostDelivery();

    // Targeted reconciliation repairs too: an operator naming a stuck order is
    // asking for it to be fixed, not described.
    $this->artisan('provisioning:reconcile', ['--order' => $order->getKey()])->assertExitCode(0);

    expect(Queue::size(Queues::Provisioning->value))->toBe(1);

    Simulator::script();
    (new ProvisionOrderJob((int) $order->getKey()))->handle($this->provisioning);

    expect($order->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and(Server::query()->count())->toBe(1);

    Queue::clear(Queues::Provisioning->value);
});

it('marks a lost delivery as safe to dispatch, and says why', function (): void {
    $result = $this->reconciliation->reconcile(lostDelivery());

    expect($result->state)->toBe(ProvisioningResult::Retryable)
        ->and($result->mayDispatch)->toBeTrue();
});

it('queues nothing when the provider could not be read', function (): void {
    $order = lostDelivery();

    $scripted = Simulator::script();
    $scripted->onListServers(function (): never {
        throw App\Cloud\Exceptions\ProviderException::unavailable(
            App\Cloud\Fake\FakeProvider::CODE, 'The API is down.',
        );
    });

    $result = $this->reconciliation->reconcile($order);

    // Retryable, but not now. An unread provider is not a lost delivery, and
    // sending a worker at it this minute helps nobody.
    expect($result->state)->toBe(ProvisioningResult::Retryable)
        ->and($result->mayDispatch)->toBeFalse();

    $this->artisan('provisioning:reconcile')->assertExitCode(0);

    expect(Queue::size(Queues::Provisioning->value))->toBe(0);
});

it('queues nothing for an order that is already finished or parked', function (): void {
    // Delivered.
    $done = $this->floor->paidOrder();
    Simulator::script();
    $this->provisioning->provision($done);
    DB::table('orders')->where('id', $done->getKey())
        ->update(['updated_at' => CarbonImmutable::now()->subHour()]);

    // Parked on an ambiguity is a person's decision, not a queue's.
    $parked = $this->floor->paidOrder();
    $this->provisioning->prepare($parked);
    DB::table('orders')->where('id', $parked->getKey())->update([
        'status' => OrderStatus::NeedsAttention->value,
        'updated_at' => CarbonImmutable::now()->subHour(),
    ]);

    Queue::clear(Queues::Provisioning->value);
    $this->artisan('provisioning:reconcile')->assertExitCode(0);

    // The parked order is genuinely absent at the provider with budget left, so
    // it is repaired; the delivered one is not touched.
    expect($done->fresh()->status)->toBe(OrderStatus::Provisioned)
        ->and(Server::query()->count())->toBe(1);

    Queue::clear(Queues::Provisioning->value);
});

it('never queues work for a spent token', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();
    $this->provisioning->provision($order);

    $remote = FakeProviderServer::query()->firstOrFail();
    Simulator::plain()->deleteServer($remote->provider_server_id);
    $scripted->afterCreate(fn ($server) => $server);

    DB::table('orders')->where('id', $order->getKey())
        ->update(['updated_at' => CarbonImmutable::now()->subHour()]);
    Queue::clear(Queues::Provisioning->value);

    $result = $this->reconciliation->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Refunded)
        ->and($result->mayDispatch)->toBeFalse()
        ->and(Queue::size(Queues::Provisioning->value))->toBe(0);
});

it('records a create-stage attempt that survives the worker dying', function (): void {
    $order = $this->floor->paidOrder();
    $seen = [];

    $scripted = Simulator::script();
    $scripted->beforeCreate(function () use ($order, &$seen): void {
        // What a worker dying right here would leave behind.
        $attempt = ProvisioningAttempt::query()->where('order_id', $order->getKey())
            ->orderByDesc('id')->first();

        $seen = [
            'attempts' => (int) DB::table('orders')->where('id', $order->getKey())->value('attempts'),
            'stage' => $attempt?->stage,
            'outcome' => $attempt?->outcome,
        ];
    });

    $this->provisioning->provision($order);

    // The budget was spent before the call, and history already says a create
    // was reached — not that one was merely about to be.
    expect($seen['attempts'])->toBe(1)
        ->and($seen['stage'])->toBe(ProvisioningStage::Create)
        ->and($seen['outcome'])->toBe(App\Enums\ProvisioningOutcome::InFlight);
});

it('spends no create attempt on a read that never reaches a create', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->onAvailability(function (): never {
        throw App\Cloud\Exceptions\ProviderException::make(
            ProviderErrorCategory::Timeout, App\Cloud\Fake\FakeProvider::CODE, 'No answer.',
        );
    });

    $this->provisioning->provision($order);

    // A forensic row exists, and the create budget is untouched: the order has
    // not yet asked anyone to build anything.
    expect(ProvisioningAttempt::query()->count())->toBe(1)
        ->and(app(CreateBudget::class)->used($order->fresh()))->toBe(0)
        ->and($scripted->callCount('createServer'))->toBe(0);
});

it('spends no create attempt on a reconciliation that only reads', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    $scripted->loseCreateResponse();
    $this->provisioning->provision($order);

    expect(app(CreateBudget::class)->used($order->fresh()))->toBe(1);

    // Recovery persists an existing machine. It creates nothing, so it costs
    // nothing from the create budget.
    $scripted->afterCreate(fn ($server) => $server);
    $this->reconciliation->reconcile($order->fresh());

    expect(app(CreateBudget::class)->used($order->fresh()))->toBe(1)
        ->and($scripted->callCount('createServer'))->toBe(1)
        ->and(ProvisioningAttempt::query()->count())->toBeGreaterThan(1);
});
