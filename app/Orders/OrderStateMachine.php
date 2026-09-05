<?php

declare(strict_types=1);

namespace App\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Orders\Exceptions\OrderTransitionConflict;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The only thing that changes an order's status.
 *
 * Two guarantees, and they are separate. The graph below says which moves the
 * lifecycle has at all, so a typo or a misunderstanding is caught before it
 * touches the database. The compare-and-set says the move only happens if the
 * order is still where the caller believed — the update carries the expected
 * status in its WHERE clause, and one affected row is the only success.
 *
 * That second part is what makes this safe under concurrency. Two workers can
 * both read an order as `paid` and both decide to move it to `provisioning`;
 * one UPDATE matches and one matches nothing, and the loser is told rather than
 * quietly overwriting the winner. A read-then-save would have both succeed and
 * the order would be provisioned twice.
 *
 * Redis is not involved. A distributed lock would coordinate this application
 * with itself while the database stayed the thing that could actually be wrong.
 */
final readonly class OrderStateMachine
{
    /**
     * The lifecycle, written out.
     *
     * Deliberately small. Every edge here is one Release 1.0 actually performs,
     * and a phase that needs another adds it with the tests that justify it —
     * a permissive graph would let a bug reach a state nobody designed for.
     *
     * @var array<string, list<OrderStatus>>
     */
    private const ALLOWED = [
        // Not yet asked for money, so it can still be called off for free.
        OrderStatus::Pending->value => [OrderStatus::AwaitingPayment, OrderStatus::Cancelled],

        // Asked for money. Either it arrives, the window closes, or the
        // customer changes their mind.
        OrderStatus::AwaitingPayment->value => [OrderStatus::Paid, OrderStatus::Expired, OrderStatus::Cancelled],

        // Funds committed. From here a failure owes the customer money back.
        OrderStatus::Paid->value => [OrderStatus::Provisioning, OrderStatus::Failed],

        // Phase 7 drives these. `needs_attention` is where an uncertain
        // outcome waits for a person, never a refund.
        OrderStatus::Provisioning->value => [
            OrderStatus::Provisioned, OrderStatus::Failed, OrderStatus::NeedsAttention,
        ],
        OrderStatus::NeedsAttention->value => [
            OrderStatus::Provisioning, OrderStatus::Provisioned, OrderStatus::Failed,
        ],

        // The only way out of failed is giving the money back.
        OrderStatus::Failed->value => [OrderStatus::Refunded],

        // Terminal. No edges out.
        OrderStatus::Provisioned->value => [],
        OrderStatus::Refunded->value => [],
        OrderStatus::Expired->value => [],
        OrderStatus::Cancelled->value => [],
    ];

    /**
     * Move an order, if it is still where you think it is.
     *
     * @param  array<string, mixed>  $alsoSet  Lifecycle columns to write in the
     *                                         same statement, so that a failure
     *                                         reason and the failed status
     *                                         cannot disagree.
     *
     * @throws OrderTransitionConflict
     */
    public function transition(
        Order $order,
        OrderStatus $expected,
        OrderStatus $target,
        array $alsoSet = [],
    ): Order {
        self::assertAllowed($expected, $target);

        $affected = Order::query()
            ->whereKey($order->getKey())
            ->where('status', $expected->value)
            ->update([...$alsoSet, 'status' => $target->value, 'updated_at' => now()]);

        if ($affected !== 1) {
            // Exactly one row, or the caller was working from a stale read.
            // Read back what is actually there so the refusal can say so.
            $found = Order::query()->whereKey($order->getKey())->first();

            throw OrderTransitionConflict::lost(
                $expected,
                $target,
                $found instanceof Order ? $found->status : null,
            );
        }

        $fresh = Order::query()->whereKey($order->getKey())->first();

        if (! $fresh instanceof Order) {
            throw new ModelNotFoundException('That order no longer exists.');
        }

        return $fresh;
    }

    /**
     * Move an order that is already locked, inside the caller's transaction.
     *
     * Same compare-and-set, and still worth doing under a row lock: the lock
     * makes the caller's read current, the CAS makes the write conditional on
     * it, and the second guarantee is cheap.
     *
     * @param  array<string, mixed>  $alsoSet
     */
    public function transitionLocked(
        Order $locked,
        OrderStatus $target,
        array $alsoSet = [],
    ): Order {
        return $this->transition($locked, $locked->status, $target, $alsoSet);
    }

    /** Whether the lifecycle has this edge at all. */
    public static function allows(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value] ?? [], strict: true);
    }

    /**
     * @return list<OrderStatus>
     */
    public static function transitionsFrom(OrderStatus $from): array
    {
        return self::ALLOWED[$from->value] ?? [];
    }

    /**
     * @throws OrderTransitionConflict
     */
    public static function assertAllowed(OrderStatus $from, OrderStatus $to): void
    {
        if (! self::allows($from, $to)) {
            throw OrderTransitionConflict::notAllowed($from, $to);
        }
    }

    /**
     * Expire an order whose payment window has closed.
     *
     * The deadline is part of the WHERE clause rather than checked first and
     * acted on second: between a read and a write the customer may have paid,
     * and expiring a paid order would take a server away from someone who
     * bought it.
     */
    public function expireIfWindowClosed(Order $order): ?Order
    {
        $affected = Order::query()
            ->whereKey($order->getKey())
            ->where('status', OrderStatus::AwaitingPayment->value)
            ->whereNotNull('awaiting_payment_expires_at')
            ->where('awaiting_payment_expires_at', '<=', now())
            ->update(['status' => OrderStatus::Expired->value, 'updated_at' => now()]);

        if ($affected !== 1) {
            return null;
        }

        $fresh = Order::query()->whereKey($order->getKey())->first();

        return $fresh instanceof Order ? $fresh : null;
    }
}
