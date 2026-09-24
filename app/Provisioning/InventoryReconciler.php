<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Enums\ProviderServerStatus;
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
        // Every provider read happened before this point and outside any
        // transaction. What follows is short, local, and decides from a locked
        // row rather than from the instance the read started with.
        DB::transaction(function () use ($server, $remote, $report): void {
            $fresh = Server::query()->whereKey($server->getKey())->lockForUpdate()->first();

            if (! $fresh instanceof Server) {
                return;
            }

            if ($fresh->status === ServerStatus::Terminated) {
                // Terminated while the provider was being read. Writing the
                // instance this sweep loaded would resurrect a machine somebody
                // else deliberately ended.
                return;
            }

            $changes = $this->desiredChanges($fresh, $remote);

            if ($changes === []) {
                return;
            }

            $fresh->forceFill($changes)->save();

            $this->audit->record(
                AuditEvent::InventoryDriftCorrected,
                subject: $fresh,
                metadata: [
                    'server_id' => $fresh->getKey(),
                    // Which fields moved, not their values: an address is not a
                    // secret but an audit entry is not a mirror of the table.
                    'fields' => implode(',', array_keys($changes)),
                ],
            );

            $report->drifted++;

            if (($changes['status'] ?? null) === ServerStatus::NeedsAttention) {
                // Money is untouched: no refund, no termination, no subscription
                // change. The provider says the machine is broken, not gone.
                $this->alerts->remoteUnhealthy($fresh, [
                    'provider_status' => $remote->status->value,
                ]);
            }
        });
    }

    /**
     * What this provider answer is entitled to change on a locked local row.
     *
     * @return array<string, mixed>
     */
    private function desiredChanges(Server $server, ProviderServerData $remote): array
    {
        $desired = [
            'ip_address' => $remote->ipv4,
            'ipv6_address' => $remote->ipv6,
            'power_state' => ServerPowerState::fromProvider($remote->powerState),
            // Already whitelisted scalars, never a raw response.
            'provider_metadata' => $remote->metadata->toArray(),
        ];

        if ($remote->status === ProviderServerStatus::Error) {
            // The provider says this machine is in an error state. It exists —
            // so it is emphatically not `missing` — but nothing may keep
            // presenting it as healthy, and `isBillable()` is false here, so a
            // renewal will not quietly charge for it either.
            if ($server->status !== ServerStatus::NeedsAttention) {
                $desired['status'] = ServerStatus::NeedsAttention;
            }
        } elseif (in_array($server->status, [ServerStatus::Missing, ServerStatus::NeedsAttention], true)) {
            // The provider now reports an ordinary state for a machine we had
            // lost or flagged. It is simply back; nothing else re-activates it.
            $desired['status'] = ServerStatus::Active;
        }

        $changes = [];

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

        return $changes;
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
            // Re-read under a row lock. The provider read that concluded this
            // machine is absent happened before the transaction opened, and
            // another transaction may have terminated the server since —
            // writing the stale instance would resurrect it as `missing`.
            $fresh = Server::query()->whereKey($server->getKey())->lockForUpdate()->first();

            if (! $fresh instanceof Server) {
                return;
            }

            if ($fresh->status === ServerStatus::Terminated) {
                // Ended deliberately while we were looking. A provider no
                // longer holding it is the expected state, not a discrepancy.
                return;
            }

            if ($fresh->status === ServerStatus::Missing) {
                // Another sweep got there first.
                $report->missing++;

                return;
            }

            $server = $fresh;
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
