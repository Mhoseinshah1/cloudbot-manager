<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Exceptions\ProviderException;
use App\Enums\ServerPowerState;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Subscription;
use App\Provisioning\Data\InventoryReport;
use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compares what a provider actually holds against what we think we sold.
 *
 * A financial control rather than an observability nicety. Every machine a
 * provider holds is being charged to us; every machine we believe in is being
 * charged to a customer. When those two lists disagree, somebody is paying for
 * nothing, and the longer it goes unnoticed the more it costs.
 *
 * Three disagreements, and the responses are deliberately asymmetric:
 *
 * - **Remote exists, local missing.** An orphan. Correlated by provisioning
 *   token where that is possible, and otherwise reported. Never deleted: a
 *   machine we cannot explain may be a customer's, and deleting it to tidy the
 *   report destroys their data.
 * - **Local active, remote missing.** Only concluded from a *complete* read of
 *   the inventory. The server is marked missing and its subscription stops
 *   being renewable, because charging again for a machine nobody can find is
 *   the failure this exists to prevent. No refund is issued automatically — the
 *   provider's own billing is a separate question a person answers.
 * - **Drift.** Addresses, power and status are corrected. Owner, order, price
 *   and snapshots are not, and the database refuses them anyway.
 *
 * A failed inventory read is never treated as an empty inventory. That single
 * inference would mark every server missing and stop every subscription.
 */
final readonly class InventoryReconciler
{
    public function __construct(
        private ReconciliationService $reconciliation,
        private OperationalAlerts $alerts,
        private AuditRecorder $audit,
    ) {}

    /**
     * Reconcile one provider's inventory against local records.
     */
    public function reconcile(Provider $provider): InventoryReport
    {
        $report = new InventoryReport($provider->code);

        // Read-only, so a disabled provider is still reconciled. Not being
        // allowed to buy from a provider does not mean we stop owing its bills.
        $driver = $this->reconciliation->readableProvider($provider->code);

        if (! $driver instanceof CloudProviderInterface) {
            $report->fail('That provider has no readable implementation.');

            return $report;
        }

        try {
            $remote = $driver->listServers();
        } catch (ProviderException $exception) {
            // The one inference never made here. An unread inventory is not an
            // empty one, and treating it as empty would mark every server this
            // provider holds as missing.
            $report->fail('The provider inventory could not be read: '.$exception->getMessage());

            $this->alerts->inventoryDiscrepancy(
                $provider,
                'inventory_unreadable',
                'inventory:provider:'.$provider->getKey().':unreadable:'.$exception->category->value,
                ['provider_code' => $provider->code, 'error_category' => $exception->category->value],
            );

            return $report;
        }

        $byRemoteId = [];

        foreach ($remote as $server) {
            $byRemoteId[$server->providerServerId] = $server;
        }

        $this->reconcileLocalServers($provider, $byRemoteId, $report);
        $this->reportOrphans($provider, $byRemoteId, $report);

        return $report;
    }

    /**
     * Walk local servers in bounded chunks, correcting what a provider may
     * correct and flagging what it may not.
     *
     * @param  array<string, ProviderServerData>  $byRemoteId
     */
    private function reconcileLocalServers(Provider $provider, array $byRemoteId, InventoryReport $report): void
    {
        Server::query()
            ->where('provider_id', $provider->getKey())
            // Terminated servers are history. A provider no longer holding one
            // is the expected state, not a discrepancy.
            ->whereNot('status', ServerStatus::Terminated->value)
            ->orderBy('id')
            // Chunked so a large estate does not arrive in memory at once.
            ->chunkById(200, function (Collection $servers) use ($byRemoteId, $report): void {
                /** @var Collection<int, Server> $servers */
                foreach ($servers as $server) {
                    $report->localChecked++;

                    $match = $byRemoteId[$server->provider_server_id] ?? null;

                    if ($match instanceof ProviderServerData && $match->status->exists()) {
                        $this->synchronize($server, $match, $report);

                        continue;
                    }

                    $this->markMissing($server, $report);
                }
            });
    }

    /**
     * Correct the fields a provider is entitled to correct.
     *
     * The whitelist is Server::PROVIDER_SYNCHRONIZED, written out by hand. A
     * provider's current answer may fix an address; it may never restate who
     * owns the machine or what it cost, and the trigger on the table refuses
     * that even if this code were wrong.
     */
    private function synchronize(Server $server, ProviderServerData $remote, InventoryReport $report): void
    {
        $changes = [];

        $desired = [
            'ip_address' => $remote->ipv4,
            'ipv6_address' => $remote->ipv6,
            'power_state' => ServerPowerState::fromProvider($remote->powerState),
            // Already whitelisted scalars, never a raw response.
            'provider_metadata' => $remote->metadata->toArray(),
        ];

        // A server we had marked missing that the provider now holds again is
        // simply back; nothing else re-activates it.
        if ($server->status === ServerStatus::Missing) {
            $desired['status'] = ServerStatus::Active;
        }

        foreach ($desired as $attribute => $value) {
            $current = $server->getAttribute($attribute);

            if ($current instanceof BackedEnum) {
                $current = $current->value;
            }

            $comparable = $value instanceof BackedEnum ? $value->value : $value;

            if ($current !== $comparable) {
                $changes[$attribute] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        DB::transaction(function () use ($server, $changes, $report): void {
            $server->forceFill($changes)->save();

            $this->audit->record(
                AuditEvent::InventoryDriftCorrected,
                subject: $server,
                metadata: [
                    'server_id' => $server->getKey(),
                    // Which fields moved, not their values: an address is not a
                    // secret but an audit entry is not a mirror of the table.
                    'fields' => implode(',', array_keys($changes)),
                ],
            );

            $report->drifted++;
        });
    }

    /**
     * A server we sold that the provider does not hold.
     *
     * Only reached after a complete inventory read succeeded, so this is a real
     * absence rather than a failure to look. The subscription stops being
     * renewable in the same transaction: Phase 11 must never charge again for a
     * machine that is not there.
     */
    private function markMissing(Server $server, InventoryReport $report): void
    {
        if ($server->status === ServerStatus::Missing) {
            // Already recorded. The alert is deduplicated too, so a daily sweep
            // does not produce a daily message about the same gap.
            $report->missing++;

            return;
        }

        DB::transaction(function () use ($server, $report): void {
            $server->forceFill(['status' => ServerStatus::Missing])->save();

            Subscription::query()
                ->where('server_id', $server->getKey())
                ->where('status', SubscriptionStatus::Active->value)
                ->update([
                    'status' => SubscriptionStatus::NeedsAttention->value,
                    'updated_at' => now(),
                ]);

            $this->audit->record(
                AuditEvent::InventoryRemoteMissing,
                subject: $server,
                metadata: [
                    'server_id' => $server->getKey(),
                    'order_id' => $server->order_id,
                    'user_id' => $server->user_id,
                    'provider_server_id' => $server->provider_server_id,
                ],
            );

            // No refund here, deliberately. Whether a customer is owed anything
            // depends on why the machine is gone, which a person establishes.
            $this->alerts->remoteMissing($server);

            $report->missing++;
        });
    }

    /**
     * Machines the provider holds that no local record explains.
     *
     * Reported, correlated where the token allows it, and never deleted. An
     * orphan may be a customer's server whose local write failed, and the tidy
     * response destroys their data.
     *
     * @param  array<string, ProviderServerData>  $byRemoteId
     */
    private function reportOrphans(Provider $provider, array $byRemoteId, InventoryReport $report): void
    {
        $known = Server::query()
            ->where('provider_id', $provider->getKey())
            ->pluck('provider_server_id')
            ->all();

        $known = array_flip(array_map(static fn (mixed $id): string => (string) $id, $known));

        foreach ($byRemoteId as $providerServerId => $remote) {
            if (array_key_exists($providerServerId, $known)) {
                continue;
            }

            $report->orphans++;

            $this->audit->record(
                AuditEvent::InventoryOrphanDetected,
                subject: $provider,
                metadata: [
                    'provider_id' => $provider->getKey(),
                    'provider_server_id' => $providerServerId,
                    // Whether it can be traced back to an order at all. The
                    // token itself is a correlation id, not a secret.
                    'provisioning_uuid' => $remote->provisioningToken,
                ],
            );

            $this->alerts->inventoryDiscrepancy(
                $provider,
                'orphan',
                'inventory:provider:'.$provider->getKey().':orphan:'.$providerServerId,
                [
                    'provider_code' => $provider->code,
                    'provider_server_id' => $providerServerId,
                    'provisioning_uuid' => $remote->provisioningToken,
                    // Whether an operator can link it, stated rather than acted on.
                    'correlatable' => $remote->provisioningToken !== null,
                ],
            );
        }
    }
}
