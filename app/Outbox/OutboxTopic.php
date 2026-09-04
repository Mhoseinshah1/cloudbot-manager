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

    /** A customer's server exists and is theirs. */
    public const ProvisioningSucceeded = 'provisioning.succeeded';

    /** An order stopped somewhere a person has to resolve. */
    public const ProvisioningNeedsAttention = 'provisioning.needs_attention';

    /** A provider refused, or refused us, in a way an operator must know about. */
    public const ProvisioningFailed = 'provisioning.failed';

    /** Local records and a provider's inventory disagree. */
    public const InventoryDiscrepancy = 'inventory.discrepancy';
}
