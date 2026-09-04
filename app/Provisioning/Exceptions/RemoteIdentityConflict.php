<?php

declare(strict_types=1);

namespace App\Provisioning\Exceptions;

use App\Models\Order;
use App\Models\Server;
use RuntimeException;

/**
 * Two orders are laying claim to one remote machine, or one order to two.
 *
 * Either direction is a contradiction that cannot be resolved by guessing.
 * Silently reassigning the machine would move a running server between paying
 * customers; silently repointing the order would abandon a machine that is
 * still being billed for by the provider.
 *
 * The database refuses this as well, through the unique index on
 * (provider_id, provider_server_id) and on servers.order_id. This is the same
 * refusal phrased so a person can act on it.
 */
final class RemoteIdentityConflict extends RuntimeException
{
    private function __construct(
        public readonly int $serverId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function claimedByAnotherOrder(Server $claimed, Order $order): self
    {
        return new self(
            (int) $claimed->getKey(),
            "Remote server {$claimed->provider_server_id} already belongs to order {$claimed->order_id}, "
            ."so it cannot also be delivered for order {$order->order_number}.",
        );
    }

    public static function alreadyDelivered(Server $existing, string $offered): self
    {
        return new self(
            (int) $existing->getKey(),
            "Order {$existing->order_id} was already delivered remote server "
            ."{$existing->provider_server_id}; it cannot now mean {$offered}.",
        );
    }
}
