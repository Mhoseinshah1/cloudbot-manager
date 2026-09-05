<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Jobs\ProvisionOrderJob;
use App\Models\Order;
use App\Provisioning\Data\ProvisioningResult;

/**
 * Puts provisioning work back on the queue after a sweep finds it missing.
 *
 * The sweeper exists to repair a worker that crashed or a queue delivery that
 * was lost, and neither is repaired by concluding that an order is retryable.
 * Something has to actually schedule the work again, and this is the one place
 * that does it — so the scheduler process itself never calls a provider, and
 * the long create keeps running on its own worker with its own timeout.
 *
 * Dispatch is deliberately narrow. Only a reconciliation that read the provider
 * successfully, found nothing for the token, and has create budget left says it
 * is safe; a provider we could not read, a machine that already exists, an
 * ambiguity or an exhausted budget all wait instead. That narrowness is not the
 * safety mechanism, though — the dispatched job re-reads the token before it
 * would create anything, so even a wrong dispatch cannot produce a second
 * machine. It simply avoids sending workers at a provider for no reason.
 */
final readonly class ProvisioningDispatcher
{
    /**
     * Schedule provisioning for this order if the result says it is safe.
     *
     * @return bool Whether work was queued.
     */
    public function dispatchIfSafe(ProvisioningResult $result): bool
    {
        if (! $result->mayDispatch) {
            return false;
        }

        $this->dispatch($result->order);

        return true;
    }

    /**
     * Queue provisioning for one order.
     *
     * Onto the dedicated provisioning queue, carrying an order id and nothing
     * else. No deduplication is attempted: two jobs for one order are harmless,
     * because both resolve the same durable token, both queue behind the same
     * Redis lock, and the provider's own idempotency and the unique constraints
     * guarantee one machine. Building a queue-uniqueness subsystem here would
     * put a second, weaker answer next to the one that already works.
     */
    public function dispatch(Order $order): void
    {
        ProvisionOrderJob::dispatch((int) $order->getKey());
    }
}
