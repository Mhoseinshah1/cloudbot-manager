<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\OrderFailureCategory;
use App\Enums\OrderStatus;
use App\Jobs\ProvisionOrderJob;
use App\Models\Order;
use App\Models\Server;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Provisioning\CreateBudget;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningService;
use App\Provisioning\ReconciliationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\Provisioning\ProvisioningFloor;
use Tests\Support\Provisioning\ScriptedProvider;
use Tests\Support\Provisioning\Simulator;

/**
 * The maximum number of times one order may ask a provider to build a server.
 *
 * A queued job's `$tries` cannot enforce this. It counts deliveries of a single
 * job instance, so a job dispatched by the sweeper, by an operator, or after a
 * worker restart arrives with a fresh counter and the business limit silently
 * resets — which is exactly how a broken provider ends up being asked to build
 * the same server twenty times.
 *
 * So the budget lives in PostgreSQL, on `orders.attempts`, is reserved before
 * the call rather than counted after it, and is enforced by the database rather
 * than by an `if` in PHP.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open();
    $this->provisioning = app(ProvisioningService::class);
    $this->budget = app(CreateBudget::class);
    $this->max = $this->budget->maximum();
});

/** A provider that always fails the create, having built nothing. */
function alwaysFailsCreate(): ScriptedProvider
{
    return Simulator::script()->rejectCreate(
        ProviderErrorCategory::TransientProviderError, 'Still broken.',
    );
}

function attemptsOf(Order $order): int
{
    return (int) DB::table('orders')->where('id', $order->getKey())->value('attempts');
}

it('counts each real create call, once, in the database', function (): void {
    $order = $this->floor->paidOrder();
    $scripted = alwaysFailsCreate();

    expect(attemptsOf($order))->toBe(0);

    foreach (range(1, $this->max) as $expected) {
        $this->provisioning->provision($order->fresh());

        expect(attemptsOf($order))->toBe($expected)
            ->and($scripted->callCount('createServer'))->toBe($expected);
    }
});

it('refuses a fourth create however many new jobs are dispatched', function (): void {
    $order = $this->floor->paidOrder();
    $scripted = alwaysFailsCreate();

    foreach (range(1, $this->max) as $ignored) {
        $this->provisioning->provision($order->fresh());
    }

    expect($scripted->callCount('createServer'))->toBe($this->max)
        ->and(attemptsOf($order))->toBe($this->max);

    // Fresh job instances, each with its own untouched queue-attempt counter.
    foreach (range(1, 4) as $ignored) {
        (new ProvisionOrderJob((int) $order->getKey()))->handle($this->provisioning);
    }

    // And a direct call, bypassing the queue entirely.
    $this->provisioning->provision($order->fresh());

    expect($scripted->callCount('createServer'))->toBe($this->max)
        ->and(attemptsOf($order))->toBe($this->max)
        ->and(attemptsOf($order))->not->toBeGreaterThan($this->max);
});

it('is not reset by a job instance carrying a different tries value', function (): void {
    $order = $this->floor->paidOrder();
    $scripted = alwaysFailsCreate();

    foreach (range(1, $this->max) as $ignored) {
        $this->provisioning->provision($order->fresh());
    }

    // The queue property is about one delivery; the business cap is not.
    $job = new ProvisionOrderJob((int) $order->getKey());
    $job->tries = 99;
    $job->handle($this->provisioning);

    expect($scripted->callCount('createServer'))->toBe($this->max)
        ->and(attemptsOf($order))->toBe($this->max);
});

it('is not reset by the sweeper dispatching again', function (): void {
    $order = $this->floor->paidOrder();
    $scripted = alwaysFailsCreate();

    foreach (range(1, $this->max) as $ignored) {
        $this->provisioning->provision($order->fresh());
    }

    // The sweeper sees an exhausted budget and a provider holding nothing:
    // that is a confirmed absence, so it refunds rather than re-queueing.
    $result = app(ReconciliationService::class)->reconcile($order->fresh());

    expect($result->mayDispatch)->toBeFalse()
        ->and($result->state)->toBe(ProvisioningResult::Refunded)
        ->and($scripted->callCount('createServer'))->toBe($this->max);
});

it('refunds once when the budget is spent and the provider holds nothing', function (): void {
    $order = $this->floor->paidOrder();
    $charged = (int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman');
    alwaysFailsCreate();

    foreach (range(1, $this->max) as $ignored) {
        $this->provisioning->provision($order->fresh());
    }

    $result = app(ReconciliationService::class)->reconcile($order->fresh());
    $fresh = $order->fresh();

    expect($result->state)->toBe(ProvisioningResult::Refunded)
        ->and($fresh->status)->toBe(OrderStatus::Refunded)
        ->and($fresh->failure_category)->toBe(OrderFailureCategory::ReconciliationConfirmedNoServer)
        ->and((int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman'))
        ->toBe($charged + $order->total_toman)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(1)
        ->and(Server::query()->count())->toBe(0);
});

it('refunds nobody when the budget is spent but the provider cannot be read', function (): void {
    $order = $this->floor->paidOrder();
    $charged = (int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman');
    $scripted = alwaysFailsCreate();

    foreach (range(1, $this->max) as $ignored) {
        $this->provisioning->provision($order->fresh());
    }

    // Now the provider goes dark. An exhausted counter is not evidence of
    // absence; only a successful read is.
    $scripted->onListServers(function (): never {
        throw App\Cloud\Exceptions\ProviderException::unavailable(
            App\Cloud\Fake\FakeProvider::CODE, 'The API is down.',
        );
    });

    $result = app(ReconciliationService::class)->reconcile($order->fresh());

    expect($result->state)->toBe(ProvisioningResult::Retryable)
        ->and($result->mayDispatch)->toBeFalse()
        ->and($order->fresh()->status)->not->toBe(OrderStatus::Refunded)
        ->and((int) User::query()->whereKey($this->floor->customer->id)->value('wallet_balance_toman'))
        ->toBe($charged)
        ->and(WalletTransaction::query()->where('idempotency_key', $order->refundIdempotencyKey())->count())
        ->toBe(0);
});

it('reserves atomically, and refuses past the maximum', function (): void {
    $order = $this->floor->paidOrder();
    $this->provisioning->prepare($order);

    foreach (range(1, $this->max) as $expected) {
        expect($this->budget->reserve($order->fresh()))->toBe($expected);
    }

    // The database is the arbiter, not a check in PHP.
    expect($this->budget->reserve($order->fresh()))->toBeNull()
        ->and($this->budget->isExhausted($order->fresh()))->toBeTrue()
        ->and(attemptsOf($order))->toBe($this->max);
});

it('reserves nothing against an order that is no longer being provisioned', function (): void {
    $order = $this->floor->paidOrder();

    // Still `paid`: nothing has claimed it, so nothing may spend its budget.
    expect($this->budget->reserve($order))->toBeNull()
        ->and(attemptsOf($order))->toBe(0);

    $this->provisioning->prepare($order);
    expect($this->budget->reserve($order->fresh()))->toBe(1);

    // Refunded orders are finished; an in-flight worker must not charge one.
    DB::table('orders')->where('id', $order->getKey())
        ->update(['status' => OrderStatus::Refunded->value]);

    expect($this->budget->reserve($order->fresh()))->toBeNull()
        ->and(attemptsOf($order))->toBe(1);
});

it('keeps forensic attempt numbering separate from the create budget', function (): void {
    $order = $this->floor->paidOrder();

    $scripted = Simulator::script();
    // One availability failure, which reaches no create.
    $scripted->onAvailability(function (): never {
        throw App\Cloud\Exceptions\ProviderException::make(
            ProviderErrorCategory::Timeout, App\Cloud\Fake\FakeProvider::CODE, 'No answer.',
        );
    });
    $this->provisioning->provision($order);

    // Then a create that fails.
    $scripted->onAvailability(fn (): bool => true);
    $scripted->rejectCreate(ProviderErrorCategory::TransientProviderError, 'Broken.');
    $this->provisioning->provision($order->fresh());

    // Two forensic rows, one create spent. Conflating them would retire this
    // order a whole attempt early.
    expect(App\Models\ProvisioningAttempt::query()->count())->toBe(2)
        ->and(attemptsOf($order))->toBe(1)
        ->and($scripted->callCount('createServer'))->toBe(1);
});
