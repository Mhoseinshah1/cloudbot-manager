<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Data\SensitiveRootCredential;
use App\Enums\OrderStatus;
use App\Enums\ServerPowerState;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Order;
use App\Models\Server;
use App\Models\Subscription;
use App\Orders\OrderStateMachine;
use App\Outbox\OutboxTopic;
use App\Outbox\OutboxWriter;
use App\Provisioning\Data\ProvisioningPlan;
use App\Provisioning\Exceptions\RemoteIdentityConflict;
use App\Provisioning\Exceptions\RemoteIdentityMismatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Writes down that a customer has their server.
 *
 * Everything in one transaction: the server, the subscription, the order's new
 * status, when it was delivered, the audit entry and the promise to tell the
 * customer. Half of that surviving is worse than none of it — a subscription
 * with no server bills for nothing, and a customer told their server is ready
 * when the row rolled back has been told something untrue.
 *
 * Safe to call again, and it will be: this runs from the provisioning job and
 * again from reconciliation, sometimes for the same order. A replay finds the
 * server that exists and returns it unchanged. It does not write a second
 * subscription, does not move the service period, and does not raise a second
 * audit entry or outbox message — a customer's 30 days do not restart because a
 * worker ran twice.
 *
 * No invoice is issued here. Phase 6 already raised exactly one purchase
 * invoice when the order was paid; a second at delivery would bill the same
 * purchase twice.
 */
final readonly class ServerPersister
{
    public function __construct(
        private OrderStateMachine $states,
        private AuditRecorder $audit,
        private OutboxWriter $outbox,
    ) {}

    /**
     * Persist a delivered server, or return the one already recorded.
     *
     * @param  CarbonImmutable|null  $activatedAt  The delivery instant. One value
     *                                             is written to both the order and
     *                                             the subscription's period start,
     *                                             so the two cannot disagree about
     *                                             when service began.
     *
     * @throws RemoteIdentityMismatch when the remote server is not the one this
     *                                order asked for.
     * @throws RemoteIdentityConflict when the remote server already belongs to a
     *                                different order.
     */
    public function persist(
        Order $order,
        ProviderServerData $remote,
        ProvisioningPlan $plan,
        ?CarbonImmutable $activatedAt = null,
        /**
         * The one-time root password, when the caller is holding one.
         *
         * Passed explicitly rather than read from the server DTO, because that
         * DTO must never carry it. This is the only route from a create or
         * reset response into durable storage, and it ends at the model's
         * encrypted cast — nothing between here and there writes it anywhere
         * else, and it is not part of the audit or outbox payload below.
         */
        #[SensitiveParameter]
        ?SensitiveRootCredential $credential = null,
    ): Server {
        $activatedAt ??= CarbonImmutable::now();

        // Checked before the transaction opens. A mismatch is not something to
        // roll back — it is a reason never to have started.
        $this->assertIdentityMatches($order, $remote, $plan);

        return DB::transaction(function () use ($order, $remote, $plan, $activatedAt, $credential): Server {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Order) {
                throw new ModelNotFoundException('That order no longer exists.');
            }

            $existing = Server::query()->where('order_id', $locked->getKey())->first();

            if ($existing instanceof Server) {
                // Already delivered. Nothing is rewritten: not the period, not
                // the financial snapshot, not the audit trail.
                $this->assertExistingMatches($existing, $remote);

                return $existing;
            }

            // Somebody else's machine must not become this customer's.
            $this->assertRemoteUnclaimed($locked, $remote);

            $server = $this->createServer($locked, $remote, $plan, $credential);
            $subscription = $this->createSubscription($locked, $server, $plan, $activatedAt);

            $provisioned = $this->markProvisioned($locked, $activatedAt);

            $this->recordAudit($provisioned, $server, $subscription);

            // Inside the transaction. A customer told their server is ready by
            // a transaction that then rolls back has been told a falsehood the
            // system cannot retract.
            $this->outbox->record(
                OutboxTopic::ProvisioningSucceeded,
                $provisioned,
                [
                    'order_id' => $provisioned->getKey(),
                    'order_number' => $provisioned->order_number,
                    'user_id' => $provisioned->user_id,
                    'server_id' => $server->getKey(),
                    'server_name' => $server->name,
                    'provider_server_id' => $server->provider_server_id,
                    'ip_address' => $server->ip_address,
                    'ipv6_address' => $server->ipv6_address,
                    // The period the customer has bought. No credential: a root
                    // password is revealed through a deliberate, audited flow,
                    // never carried in a notification payload.
                    'current_period_start' => $subscription->current_period_start->toIso8601String(),
                    'current_period_end' => $subscription->current_period_end->toIso8601String(),
                ],
                self::successDeduplicationKey($provisioned),
            );

            return $server;
        });
    }

    /** The key that makes one delivery produce one customer notification. */
    public static function successDeduplicationKey(Order $order): string
    {
        return 'provisioning:order:'.$order->getKey().':success';
    }

    private function createServer(
        Order $order,
        ProviderServerData $remote,
        ProvisioningPlan $plan,
        #[SensitiveParameter]
        ?SensitiveRootCredential $credential,
    ): Server {
        $server = Server::query()->create([
            'user_id' => $order->user_id,
            'order_id' => $order->getKey(),
            'product_id' => $plan->productId,
            'provider_id' => $plan->providerId,
            'provider_location_id' => $plan->providerLocationId,
            'provider_server_id' => $remote->providerServerId,
            'provisioning_uuid' => (string) $order->provisioning_uuid,
            'name' => $remote->name,
            'ip_address' => $remote->ipv4,
            'ipv6_address' => $remote->ipv6,
            'plan_snapshot' => $plan->planSnapshot,
            'image_snapshot' => $plan->imageSnapshot,
            // Already whitelisted by the provider adapter. Stored as the scalars
            // it kept, never as the response they came from.
            'provider_metadata' => $remote->metadata->toArray(),
            'status' => ServerStatus::Active->value,
            'power_state' => ServerPowerState::fromProvider($remote->powerState)->value,
            'billing_mode' => $plan->billingMode->value,
            // The order's frozen numbers, copied exactly. Not recomputed from
            // today's rate, and not rounded: fractional Toman are real in a
            // derived cost, whatever the column name suggests.
            'provider_cost' => $plan->providerCost,
            'provider_currency' => $plan->providerCurrency,
            'exchange_rate' => $plan->exchangeRate,
            'local_cost_toman' => $plan->localCostToman,
            'selling_price_toman' => $plan->sellingPriceToman,
            'gross_margin_toman' => $plan->grossMarginToman,
            // Written inside the same transaction as the server it belongs to,
            // through the model's encrypted cast. Null when this provider
            // issues no password, and null is honest — it is what makes the
            // reveal flow refuse rather than show an empty string.
            'root_password_encrypted' => $credential?->reveal(),
        ]);

        $this->audit->record(
            AuditEvent::ServerCreated,
            subject: $server,
            metadata: [
                'server_id' => $server->getKey(),
                'order_id' => $order->getKey(),
                'user_id' => $order->user_id,
                'provider_id' => $plan->providerId,
                'provider_server_id' => $server->provider_server_id,
                // Whether a credential was stored, never the credential. An
                // operator needs to know a machine has a password on file; the
                // password itself has exactly one home.
                'has_root_credential' => $credential instanceof SensitiveRootCredential,
            ],
        );

        return $server;
    }

    /**
     * The customer's service period, established exactly once.
     *
     * Exactly 2,592,000 seconds — 30 × 24 hours — added to the delivery instant.
     * Elapsed time, not a calendar month: `addMonth()` would give a February
     * customer 28 days and a March customer 31 for the same money, and would
     * need an overflow rule for the 31st that no requirement states. See
     * docs/decisions/ADR-001.
     */
    private function createSubscription(
        Order $order,
        Server $server,
        ProvisioningPlan $plan,
        CarbonImmutable $activatedAt,
    ): Subscription {
        $subscription = Subscription::query()->create([
            'user_id' => $order->user_id,
            'server_id' => $server->getKey(),
            'product_id' => $plan->productId,
            'status' => SubscriptionStatus::Active->value,
            'current_period_start' => $activatedAt,
            // Seconds, added. Not months.
            'current_period_end' => $activatedAt->addSeconds(Subscription::PERIOD_SECONDS),
            'price_toman' => $order->total_toman,
            'billing_cycle' => $plan->billingCycle->value,
            'billing_mode' => $plan->billingMode->value,
            // Phase 11 owns cancellation. Nothing here presumes it.
            'cancel_at_period_end' => false,
        ]);

        $this->audit->record(
            AuditEvent::SubscriptionCreated,
            subject: $subscription,
            metadata: [
                'subscription_id' => $subscription->getKey(),
                'server_id' => $server->getKey(),
                'order_id' => $order->getKey(),
                'current_period_start' => $subscription->current_period_start->toIso8601String(),
                'current_period_end' => $subscription->current_period_end->toIso8601String(),
                'period_seconds' => Subscription::PERIOD_SECONDS,
            ],
        );

        return $subscription;
    }

    /**
     * Move the order to provisioned, from wherever it legitimately was.
     *
     * Both `provisioning` and `needs_attention` are real starting points: the
     * happy path arrives from the first, and a recovery that finds the server
     * arrives from the second. The compare-and-set still applies, so a stale
     * caller loses rather than overwriting.
     */
    private function markProvisioned(Order $order, CarbonImmutable $activatedAt): Order
    {
        if ($order->status === OrderStatus::Provisioned) {
            return $order;
        }

        return $this->states->transition(
            $order,
            $order->status,
            OrderStatus::Provisioned,
            [
                // The same instant the subscription starts from.
                'provisioned_at' => $activatedAt,
                // The order succeeded; an earlier attempt's failure note would
                // otherwise sit on a delivered purchase and read as unresolved.
                'failure_category' => null,
                'failure_reason' => null,
            ],
        );
    }

    private function recordAudit(Order $order, Server $server, Subscription $subscription): void
    {
        $this->audit->record(
            AuditEvent::OrderProvisioned,
            subject: $order,
            after: ['status' => OrderStatus::Provisioned->value],
            metadata: [
                'order_id' => $order->getKey(),
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
                'server_id' => $server->getKey(),
                'subscription_id' => $subscription->getKey(),
                'provisioned_at' => $order->provisioned_at?->toIso8601String(),
            ],
        );
    }

    /**
     * Is this the machine the order asked for?
     *
     * Checked field by field rather than trusted. A provider that answers for
     * the wrong request, or a lookup that matched on something loose, would
     * otherwise hand a customer a machine of a different size in a different
     * country and record it as their purchase.
     *
     * @throws RemoteIdentityMismatch
     */
    private function assertIdentityMatches(Order $order, ProviderServerData $remote, ProvisioningPlan $plan): void
    {
        $token = (string) $order->provisioning_uuid;

        if (trim($remote->providerServerId) === '') {
            throw RemoteIdentityMismatch::on($order, 'provider_server_id', 'non-empty', '');
        }

        if ($token === '' || $remote->provisioningToken !== $token) {
            throw RemoteIdentityMismatch::on(
                $order, 'provisioning_token', $token, $remote->provisioningToken,
            );
        }

        if ($remote->providerPlanId !== $plan->providerPlanCode) {
            throw RemoteIdentityMismatch::on(
                $order, 'plan', $plan->providerPlanCode, $remote->providerPlanId,
            );
        }

        if ($remote->providerLocationId !== $plan->providerLocationCode) {
            throw RemoteIdentityMismatch::on(
                $order, 'location', $plan->providerLocationCode, $remote->providerLocationId,
            );
        }

        if ($remote->providerImageId !== $plan->providerImageCode) {
            throw RemoteIdentityMismatch::on(
                $order, 'image', $plan->providerImageCode, $remote->providerImageId,
            );
        }
    }

    /**
     * An order cannot quietly change which machine it means.
     *
     * @throws RemoteIdentityConflict
     */
    private function assertExistingMatches(Server $existing, ProviderServerData $remote): void
    {
        if ($existing->provider_server_id !== $remote->providerServerId) {
            throw RemoteIdentityConflict::alreadyDelivered(
                $existing, $remote->providerServerId,
            );
        }
    }

    /**
     * Is this machine already somebody else's?
     *
     * The unique index on (provider_id, provider_server_id) refuses this too;
     * checking first turns a constraint violation into a decision a person can
     * read, and stops the insert from aborting the surrounding transaction.
     *
     * @throws RemoteIdentityConflict
     */
    private function assertRemoteUnclaimed(Order $order, ProviderServerData $remote): void
    {
        $claimed = Server::query()
            ->where('provider_id', $order->cost_snapshot['provider_id'] ?? 0)
            ->where('provider_server_id', $remote->providerServerId)
            ->first();

        if ($claimed instanceof Server) {
            throw RemoteIdentityConflict::claimedByAnotherOrder($claimed, $order);
        }
    }
}
