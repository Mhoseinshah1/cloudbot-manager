<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Order;
use App\Provisioning\Data\ProvisioningResult;
use App\Provisioning\ProvisioningDispatcher;
use App\Provisioning\ReconciliationService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finds orders that were left half-built, and finishes or resolves them.
 *
 * Runs every five minutes. It exists because the gap between a provider acting
 * and us recording it cannot be closed — a worker can die inside it, a queue
 * retry can be lost, a response can never arrive — and something has to notice.
 *
 * Automatic selection needs the configured stuck threshold. Without it the sweep
 * refuses to guess which orders are late rather than inventing a number that
 * silently decides how long a customer waits. A named order can always be
 * reconciled by hand, which is the operator's escape hatch when the setting is
 * exactly what is broken.
 */
final class ReconcileProvisioningCommand extends Command
{
    protected $signature = 'provisioning:reconcile
        {--order= : Reconcile one order by id, ignoring the stuck threshold}
        {--limit= : How many stuck orders to claim in this run}';

    protected $description = 'Resolve orders whose provisioning outcome is unknown, using their provisioning token';

    public function handle(ReconciliationService $reconciliation, ProvisioningDispatcher $dispatcher): int
    {
        $one = $this->option('order');

        if ($one !== null && $one !== '') {
            return $this->reconcileOne($reconciliation, $dispatcher, (int) $one);
        }

        $limit = $this->option('limit');
        $orders = $reconciliation->stuckOrders($limit === null || $limit === '' ? null : (int) $limit);

        if ($orders === null) {
            // Fail closed and say why. A sweep that silently did nothing is
            // indistinguishable from a sweep that found nothing.
            $this->error(
                'provisioning.stuck_after_minutes is not set to a readable, non-negative integer, '
                .'so stuck orders cannot be selected automatically. Reconcile a specific order with --order.'
            );

            return self::FAILURE;
        }

        if ($orders->isEmpty()) {
            $this->info('No orders are waiting to be reconciled.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($orders as $order) {
            try {
                $result = $reconciliation->reconcile($order);

                // The repair itself. A sweep that only reported "retryable"
                // would leave a lost queue delivery lost forever.
                $this->report($order, $result, $dispatcher->dispatchIfSafe($result));
            } catch (Throwable $exception) {
                // One order that cannot be reconciled must not stop the rest:
                // the others are customers waiting too.
                $failures++;
                $this->error("Order {$order->order_number}: ".$exception->getMessage());
            }
        }

        $this->info("Reconciled {$orders->count()} order(s).");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function reconcileOne(
        ReconciliationService $reconciliation,
        ProvisioningDispatcher $dispatcher,
        int $orderId,
    ): int {
        $order = Order::query()->whereKey($orderId)->first();

        if (! $order instanceof Order) {
            $this->error("No order with id {$orderId}.");

            return self::FAILURE;
        }

        try {
            $result = $reconciliation->reconcile($order);

            // Targeted reconciliation repairs too. An operator naming a stuck
            // order is asking for it to be fixed, not described.
            $this->report($order, $result, $dispatcher->dispatchIfSafe($result));
        } catch (Throwable $exception) {
            $this->error("Order {$order->order_number}: ".$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function report(Order $order, ProvisioningResult $result, bool $dispatched = false): void
    {
        $line = "{$order->order_number}: {$result->state}";

        if ($result->detail !== null) {
            $line .= ' — '.$result->detail;
        }

        if ($dispatched) {
            $line .= ' (provisioning queued)';
        }

        // needs_attention is the outcome an operator most needs to see, so it
        // is the one that stands out rather than scrolling past.
        $result->state === ProvisioningResult::NeedsAttention
            ? $this->warn($line)
            : $this->line($line);
    }
}
