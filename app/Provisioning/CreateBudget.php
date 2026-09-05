<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * How many times this order may ask a provider to build a server.
 *
 * The budget lives in PostgreSQL, on `orders.attempts`, and nowhere else. A
 * queued job's `$tries` cannot do this job: it counts deliveries of one job
 * instance, so a newly dispatched job — from the sweeper, from an operator,
 * from a worker restart — arrives with a fresh counter and the business limit
 * silently resets. The whole point of a maximum is that it survives exactly
 * those events.
 *
 * A reservation is taken *before* the provider is called and committed on its
 * own, so a worker that dies mid-create has still spent its attempt. Counting
 * afterwards would let a crash loop create servers forever, each one leaving no
 * record that it happened.
 *
 * The count is deliberately narrow: it means "create calls reserved", not
 * reconciliation reads, availability checks, local persistence retries or queue
 * deliveries. Those are forensic history and live on ProvisioningAttempt, which
 * numbers every call including the ones that never reached a create.
 */
final readonly class CreateBudget
{
    public function __construct(private Config $config) {}

    /** The configured maximum number of provider create calls per order. */
    public function maximum(): int
    {
        $max = (int) $this->config->get('cloudbot.provisioning.max_attempts', 3);

        // A non-positive maximum would mean no order can ever be built, which
        // is never the intent of a misconfiguration.
        return max(1, $max);
    }

    /** How many create calls this order has already reserved. */
    public function used(Order $order): int
    {
        return (int) DB::table('orders')->where('id', $order->getKey())->value('attempts');
    }

    public function remaining(Order $order): int
    {
        return max(0, $this->maximum() - $this->used($order));
    }

    public function isExhausted(Order $order): bool
    {
        return $this->remaining($order) === 0;
    }

    /**
     * Claim one create attempt, or refuse.
     *
     * Atomic, and PostgreSQL is the arbiter. A read-then-save would let two
     * workers both see "two of three used" and both create, which is the exact
     * failure a maximum exists to prevent — so the limit is part of the WHERE
     * clause and exactly one affected row is the only success.
     *
     * The status condition keeps the budget tied to an order that is actually
     * being provisioned: an order that has been refunded or delivered while
     * this worker was thinking must not have an attempt spent against it.
     *
     * @return int|null The attempt number reserved, or null if none was.
     */
    public function reserve(Order $order): ?int
    {
        $affected = DB::table('orders')
            ->where('id', $order->getKey())
            ->where('attempts', '<', $this->maximum())
            ->whereIn('status', [
                OrderStatus::Provisioning->value,
                OrderStatus::NeedsAttention->value,
            ])
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            return null;
        }

        return $this->used($order);
    }
}
