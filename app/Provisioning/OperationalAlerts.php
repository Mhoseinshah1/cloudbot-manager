<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Models\Order;
use App\Models\Server;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable intents to tell an operator something is wrong.
 *
 * Nothing here sends anything. Delivery is the notification phase's; what
 * matters now is that the intent is recorded in the same transaction as the
 * state it describes, so an order cannot end up parked in needs_attention with
 * nobody ever told.
 *
 * Payloads are identifiers, categories and counts. Never a provider response,
 * never an exception, never a credential — an alert is read by a person in a
 * chat window, which is the last place a secret should surface.
 */
final readonly class OperationalAlerts
{
    public function __construct(private OutboxWriter $outbox) {}

    /**
     * An order is parked for a person.
     *
     * Deduplicated per order and reason, so a sweeper that runs every five
     * minutes does not produce a message every five minutes about the same
     * stuck order.
     *
     * @param  array<string, scalar|null>  $facts
     */
    public function orderNeedsAttention(Order $order, string $reason, array $facts = []): void
    {
        $this->outbox->record(
            OutboxTopic::ProvisioningNeedsAttention,
            $order,
            [
                'order_id' => $order->getKey(),
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
                'reason' => $reason,
                'provisioning_uuid' => $order->provisioning_uuid,
                ...$facts,
            ],
            'provisioning:order:'.$order->getKey().':needs_attention:'.$reason,
        );
    }

    /**
     * A provider failed in a way an operator has to act on.
     *
     * Authentication, authorization and an empty provider account are the cases
     * that matter: no retry can fix them, and every paid order behind them is
     * stuck until somebody does something.
     *
     * @param  array<string, scalar|null>  $facts
     */
    public function providerFailure(Order $order, string $category, array $facts = []): void
    {
        $this->outbox->record(
            OutboxTopic::ProvisioningFailed,
            $order,
            [
                'order_id' => $order->getKey(),
                'order_number' => $order->order_number,
                'error_category' => $category,
                ...$facts,
            ],
            'provisioning:order:'.$order->getKey().':failed:'.$category,
        );
    }

    /**
     * Local records and a provider's inventory disagree.
     *
     * @param  array<string, scalar|null>  $facts
     */
    public function inventoryDiscrepancy(
        Model $subject,
        string $kind,
        string $deduplicationKey,
        array $facts = [],
    ): void {
        $this->outbox->record(
            OutboxTopic::InventoryDiscrepancy,
            $subject,
            ['kind' => $kind, ...$facts],
            $deduplicationKey,
        );
    }

    /**
     * A server we sold has no remote counterpart.
     *
     * @param  array<string, scalar|null>  $facts
     */
    public function remoteMissing(Server $server, array $facts = []): void
    {
        $this->inventoryDiscrepancy(
            $server,
            'remote_missing',
            'inventory:server:'.$server->getKey().':remote_missing',
            [
                'server_id' => $server->getKey(),
                'user_id' => $server->user_id,
                'provider_id' => $server->provider_id,
                'provider_server_id' => $server->provider_server_id,
                ...$facts,
            ],
        );
    }
}
