<?php

declare(strict_types=1);

namespace App\Provisioning\Data;

use App\Enums\ProvisioningOutcome;
use App\Models\Order;
use App\Models\Server;

/**
 * What one run of the provisioning coordinator concluded.
 *
 * A return value rather than an exception for the ordinary outcomes, because
 * most of them are not errors: a paused switch, a lock somebody else holds and
 * an order already delivered are all normal, and the caller's next move differs
 * for each. Reserving exceptions for the genuinely exceptional keeps the job's
 * retry decision readable.
 */
final readonly class ProvisioningResult
{
    private function __construct(
        public string $state,
        public Order $order,
        public ?Server $server = null,
        public ?ProvisioningOutcome $outcome = null,
        public ?string $detail = null,
    ) {}

    /** A server exists and belongs to the customer. */
    public const Provisioned = 'provisioned';

    /** Nothing was attempted: the operator has paused provisioning. */
    public const Paused = 'paused';

    /** Somebody else holds this order's lock. No provider call was made. */
    public const Contended = 'contended';

    /** The order is not in a state this coordinator may act on. */
    public const NotEligible = 'not_eligible';

    /** A remote server exists but is not yet usable. Reconcile later. */
    public const RemotePending = 'remote_pending';

    /** Confirmed no server, money returned. */
    public const Refunded = 'refunded';

    /** Parked for a person. Money untouched. */
    public const NeedsAttention = 'needs_attention';

    /** A transient problem. The same token may be tried again later. */
    public const Retryable = 'retryable';

    public static function provisioned(Order $order, Server $server, ProvisioningOutcome $outcome): self
    {
        return new self(self::Provisioned, $order, $server, $outcome);
    }

    public static function paused(Order $order): self
    {
        return new self(self::Paused, $order, detail: 'Provisioning is switched off.');
    }

    public static function contended(Order $order): self
    {
        return new self(self::Contended, $order, detail: 'Another worker holds this order.');
    }

    public static function notEligible(Order $order, string $detail): self
    {
        return new self(self::NotEligible, $order, detail: $detail);
    }

    public static function remotePending(Order $order, string $detail): self
    {
        return new self(self::RemotePending, $order, outcome: ProvisioningOutcome::InFlight, detail: $detail);
    }

    public static function refunded(Order $order, ProvisioningOutcome $outcome, string $detail): self
    {
        return new self(self::Refunded, $order, outcome: $outcome, detail: $detail);
    }

    public static function needsAttention(Order $order, ProvisioningOutcome $outcome, string $detail): self
    {
        return new self(self::NeedsAttention, $order, outcome: $outcome, detail: $detail);
    }

    public static function retryable(Order $order, ProvisioningOutcome $outcome, string $detail): self
    {
        return new self(self::Retryable, $order, outcome: $outcome, detail: $detail);
    }

    /** Whether the job that produced this should be tried again later. */
    public function shouldRetry(): bool
    {
        return in_array($this->state, [self::Contended, self::Retryable, self::RemotePending], strict: true);
    }
}
