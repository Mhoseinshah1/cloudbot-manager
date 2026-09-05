<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Finds paid orders whose provisioning work was lost before it ever claimed one.
 *
 * The gap this closes is narrow and was invisible to everything else. An order
 * is paid, its provisioning intent is written and delivered, and the job runs
 * while the operator has provisioning switched off. The job returns paused,
 * which is not retryable — correctly, because retrying a switch somebody turned
 * off is arguing with them — and the outbox row that dispatched it is already
 * marked processed. The order is left `paid` with no provisioning token, and
 * the stuck-order sweep cannot see it: that sweep looks for provisioning that
 * started and stalled, and this never started. Switching provisioning back on
 * schedules nothing, because nothing durable is left to schedule from.
 *
 * The same shape covers every other way that first delivery can be lost — a
 * worker killed before it ran, a provider disabled at the moment it did, a
 * queue that dropped it — because none of them leaves a token behind either.
 *
 * Redispatching is safe however many times it happens. The order's durable
 * provisioning token is what guarantees one machine, and an order that has
 * already claimed one is no longer selected here at all.
 *
 * Nothing in this class calls a provider. It finds work and hands it to the
 * provisioning queue, so the scheduler process never sits inside somebody
 * else's network timeout.
 */
final readonly class PaidOrderRecovery
{
    public function __construct(
        private SettingsService $settings,
        private ProvisioningDispatcher $dispatcher,
    ) {}

    /**
     * Paid orders with no server and no provisioning work left to find them.
     *
     * Bounded and paginated, like every other sweep selector here: an unbounded
     * query over a growing table is how a sweeper that ran fine for a year
     * takes the application down.
     *
     * Returns null when the stuck threshold cannot be read, so automatic
     * selection fails closed rather than inventing the number that decides how
     * long a paid customer waits before anybody looks.
     *
     * @return Collection<int, Order>|null
     */
    public function lostOrders(?int $limit = null): ?Collection
    {
        $minutes = $this->settings->integer(SettingKey::ProvisioningStuckAfterMinutes);

        if ($minutes === null || $minutes < 0) {
            return null;
        }

        $limit ??= (int) config('cloudbot.provisioning.reconcile_batch', 100);
        $cutoff = CarbonImmutable::now()->subMinutes($minutes);

        return Order::query()
            // Paid and nothing more. `provisioning` and `needs_attention` have
            // a token and belong to the reconciliation sweep; every other
            // status either never took money or has already given it back.
            ->where('status', OrderStatus::Paid->value)
            // The proof that this is a provisionable purchase rather than some
            // future order shape that happens to reach paid. An order with no
            // catalog line has nothing to build.
            ->whereNotNull('product_location_price_id')
            // Belt and braces against a delivered order somehow left at paid.
            ->whereDoesntHave('server')
            // Long enough that a worker holding it right now is not swept out
            // from under itself.
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * Queue provisioning for every paid order that has lost its work.
     *
     * Returns null when selection failed closed, so the caller can say why
     * rather than reporting an empty sweep.
     *
     * @return int|null How many were queued.
     */
    public function recover(?int $limit = null): ?int
    {
        if ($this->provisioningIsPaused()) {
            // Nothing is dispatched while the switch is off. The orders stay
            // exactly where they are and this sweep finds them again the first
            // time it runs after somebody turns provisioning back on — which is
            // the entire behaviour that was missing.
            return 0;
        }

        $orders = $this->lostOrders($limit);

        if ($orders === null) {
            return null;
        }

        foreach ($orders as $order) {
            $this->dispatcher->dispatch($order);
        }

        return $orders->count();
    }

    /**
     * Whether the operator has paused provisioning.
     *
     * Fails closed, matching ProvisioningService: absent or malformed means
     * paused, because nothing about an unreadable row says it is safe to start
     * spending money at a third party.
     */
    public function provisioningIsPaused(): bool
    {
        return $this->settings->boolean(SettingKey::ProvisioningEnabled) !== true;
    }
}
