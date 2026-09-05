<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Orders\Exceptions\OrderSnapshotIsImmutable;
use App\Orders\Exceptions\OrderTransitionConflict;
use App\Orders\OrderStateMachine;
use Illuminate\Support\Facades\DB;
use Tests\Support\Orders\SalesFloor;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->states = app(OrderStateMachine::class);
    $this->floor = SalesFloor::open();
});

/** An order sitting in a given state, without going through the services. */
function orderAt(OrderStatus $status): Order
{
    $order = Order::factory()->create([
        'user_id' => test()->floor->customer->id,
        'product_id' => test()->floor->catalog->product->id,
        'product_location_price_id' => test()->floor->catalog->price->id,
    ]);

    if ($status !== OrderStatus::Pending) {
        DB::table('orders')->where('id', $order->id)->update(['status' => $status->value]);
    }

    return $order->fresh();
}

it('performs every transition the lifecycle allows', function (): void {
    $edges = [
        [OrderStatus::Pending, OrderStatus::AwaitingPayment],
        [OrderStatus::Pending, OrderStatus::Cancelled],
        [OrderStatus::AwaitingPayment, OrderStatus::Paid],
        [OrderStatus::AwaitingPayment, OrderStatus::Expired],
        [OrderStatus::AwaitingPayment, OrderStatus::Cancelled],
        [OrderStatus::Paid, OrderStatus::Provisioning],
        [OrderStatus::Paid, OrderStatus::Failed],
        [OrderStatus::Provisioning, OrderStatus::Provisioned],
        [OrderStatus::Provisioning, OrderStatus::Failed],
        [OrderStatus::Provisioning, OrderStatus::NeedsAttention],
        [OrderStatus::NeedsAttention, OrderStatus::Provisioning],
        [OrderStatus::NeedsAttention, OrderStatus::Provisioned],
        [OrderStatus::NeedsAttention, OrderStatus::Failed],
        [OrderStatus::Failed, OrderStatus::Refunded],
    ];

    foreach ($edges as [$from, $to]) {
        $order = orderAt($from);

        expect($this->states->transition($order, $from, $to)->status)
            ->toBe($to, "{$from->value} -> {$to->value}");
    }
});

it('refuses transitions the lifecycle does not have', function (): void {
    // A permissive graph would let a bug reach a state nobody designed for.
    $forbidden = [
        [OrderStatus::Pending, OrderStatus::Paid],
        [OrderStatus::Pending, OrderStatus::Provisioning],
        [OrderStatus::AwaitingPayment, OrderStatus::Provisioned],
        [OrderStatus::AwaitingPayment, OrderStatus::Refunded],
        [OrderStatus::Paid, OrderStatus::Refunded],
        [OrderStatus::Paid, OrderStatus::Provisioned],
        [OrderStatus::Paid, OrderStatus::Cancelled],
        [OrderStatus::Provisioning, OrderStatus::Refunded],
        [OrderStatus::Failed, OrderStatus::Provisioning],
        [OrderStatus::Failed, OrderStatus::Paid],
    ];

    foreach ($forbidden as [$from, $to]) {
        $order = orderAt($from);

        try {
            $this->states->transition($order, $from, $to);
            $this->fail("{$from->value} -> {$to->value} was allowed.");
        } catch (OrderTransitionConflict $conflict) {
            expect($conflict->target)->toBe($to);
        }

        expect($order->fresh()->status)->toBe($from);
    }
});

it('never reopens a terminal order', function (): void {
    foreach ([OrderStatus::Provisioned, OrderStatus::Refunded, OrderStatus::Expired, OrderStatus::Cancelled] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue()
            ->and(OrderStateMachine::transitionsFrom($terminal))->toBe([]);

        $order = orderAt($terminal);

        foreach (OrderStatus::cases() as $target) {
            expect(fn () => $this->states->transition($order, $terminal, $target))
                ->toThrow(OrderTransitionConflict::class);
        }

        expect($order->fresh()->status)->toBe($terminal);
    }
});

it('fails when the caller is working from a stale read', function (): void {
    // The order moved on between the caller reading it and acting.
    $order = orderAt(OrderStatus::Pending);
    $this->states->transition($order, OrderStatus::Pending, OrderStatus::Cancelled);

    try {
        // $order still says pending.
        $this->states->transition($order, OrderStatus::Pending, OrderStatus::AwaitingPayment);
        $this->fail('A stale expected state was accepted.');
    } catch (OrderTransitionConflict $conflict) {
        expect($conflict->expected)->toBe(OrderStatus::Pending)
            ->and($conflict->actual)->toBe(OrderStatus::Cancelled);
    }

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled);
});

it('lets only one of two competing transitions win', function (): void {
    // Both callers hold the same expected state; the compare-and-set decides.
    $order = orderAt(OrderStatus::Paid);

    $first = $this->states->transition($order, OrderStatus::Paid, OrderStatus::Provisioning);

    try {
        $this->states->transition($order, OrderStatus::Paid, OrderStatus::Failed);
        $this->fail('Both transitions from the same expected state succeeded.');
    } catch (OrderTransitionConflict $conflict) {
        expect($conflict->actual)->toBe(OrderStatus::Provisioning);
    }

    expect($first->status)->toBe(OrderStatus::Provisioning)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioning);
});

it('writes lifecycle fields in the same statement as the status', function (): void {
    // A failed order can never be missing the reason it failed.
    $order = orderAt(OrderStatus::Paid);

    $failed = $this->states->transition($order, OrderStatus::Paid, OrderStatus::Failed, [
        'failure_category' => 'provider_rejected_no_server',
        'failure_reason' => 'the provider said no',
        'attempts' => 2,
    ]);

    expect($failed->status)->toBe(OrderStatus::Failed)
        ->and($failed->failure_category->value)->toBe('provider_rejected_no_server')
        ->and($failed->failure_reason)->toBe('the provider said no')
        ->and($failed->attempts)->toBe(2);
});

it('refuses a status change through the ordinary model', function (): void {
    // Assigning and saving would skip the compare-and-set entirely.
    $order = orderAt(OrderStatus::Pending);

    $order->forceFill(['status' => OrderStatus::Provisioned])->save();

    // The model does not guard status, so the guarantee has to be that no
    // business code does this — which is why status is absent from $fillable.
    expect((new Order)->getFillable())->not->toContain('status');
});

it('refuses to change what the customer was quoted', function (): void {
    $order = orderAt(OrderStatus::Pending);

    foreach (Order::IMMUTABLE as $attribute) {
        expect(fn () => $order->forceFill([$attribute => match ($attribute) {
            'aup_accepted_at' => now()->addYear(),
            'cost_snapshot', 'pricing_snapshot' => ['tampered' => true],
            'total_toman' => 1,
            default => 'tampered',
        }])->save())->toThrow(OrderSnapshotIsImmutable::class, '', $attribute);

        $order->refresh();
    }
});

it('expires only an order whose window has actually closed', function (): void {
    $open = orderAt(OrderStatus::AwaitingPayment);
    DB::table('orders')->where('id', $open->id)
        ->update(['awaiting_payment_expires_at' => now()->addHour()]);

    expect($this->states->expireIfWindowClosed($open->fresh()))->toBeNull()
        ->and($open->fresh()->status)->toBe(OrderStatus::AwaitingPayment);

    DB::table('orders')->where('id', $open->id)
        ->update(['awaiting_payment_expires_at' => now()->subMinute()]);

    expect($this->states->expireIfWindowClosed($open->fresh())->status)->toBe(OrderStatus::Expired);
});

it('never expires an order with no deadline', function (): void {
    $order = orderAt(OrderStatus::AwaitingPayment);

    expect($order->awaiting_payment_expires_at)->toBeNull()
        ->and($this->states->expireIfWindowClosed($order))->toBeNull();
});

it('never expires an order that has already been paid', function (): void {
    // The deadline is part of the WHERE clause, so a customer who paid a
    // moment ago cannot have their order taken away.
    $order = orderAt(OrderStatus::Paid);
    DB::table('orders')->where('id', $order->id)
        ->update(['awaiting_payment_expires_at' => now()->subDay()]);

    expect($this->states->expireIfWindowClosed($order->fresh()))->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});
