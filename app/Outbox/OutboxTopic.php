<?php

declare(strict_types=1);

namespace App\Outbox;

/**
 * The kinds of post-commit work this system records.
 *
 * Named once so the writer and the future worker cannot disagree by a typo.
 * Only the topic this phase actually writes is declared; the rest arrive with
 * the code that emits them.
 */
final class OutboxTopic
{
    /** A customer's order failed and their money went back to their wallet. */
    public const OrderRefunded = 'order.refunded';

    /**
     * A customer paid, and a server is owed.
     *
     * Written inside the transaction that takes the money, because the moment
     * between "paid" and "provisioning job dispatched" is otherwise a hole
     * nothing can see into: an order sitting at paid with no provisioning
     * token is invisible to the stuck-provisioning sweep, which looks for
     * orders that started and stalled. A worker that dies in that gap leaves a
     * customer charged for a machine nobody will ever build.
     */
    public const ProvisioningRequested = 'provisioning.requested';

    /** A customer's server exists and is theirs. */
    public const ProvisioningSucceeded = 'provisioning.succeeded';

    /** Somebody asked for something to be done to a server. */
    public const ServerActionRequested = 'server.action_requested';

    /** A customer's server has been deleted and their service has ended. */
    public const ServerTerminated = 'server.terminated';

    /** An order stopped somewhere a person has to resolve. */
    public const ProvisioningNeedsAttention = 'provisioning.needs_attention';

    /** A provider refused, or refused us, in a way an operator must know about. */
    public const ProvisioningFailed = 'provisioning.failed';

    /** Local records and a provider's inventory disagree. */
    public const InventoryDiscrepancy = 'inventory.discrepancy';
}
