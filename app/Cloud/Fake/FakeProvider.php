<?php

declare(strict_types=1);

namespace App\Cloud\Fake;

use App\Cloud\Capabilities\SupportsPowerControl;
use App\Cloud\Capabilities\SupportsReboot;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Data\SafeMetadata;
use App\Cloud\Enums\ProviderActionStatus;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Enums\ProviderPowerState;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\Fake\Models\FakeProviderAction;
use App\Cloud\Fake\Models\FakeProviderServer;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A cloud provider that never touches the network.
 *
 * Used by the test suite, local development and staging demonstrations. It
 * implements the same contract as a real provider so the code above it cannot
 * tell the difference, which is what makes it useful: the ordering, refund and
 * provisioning logic built in later phases can be driven through every path,
 * including the awkward ones, without spending money or waiting on an API.
 *
 * Its state lives in PostgreSQL, not in this object. Two instances of this
 * class, a queue worker and a web request all see the same simulated provider,
 * and it survives the process restarting — as a real provider obviously would.
 */
final class FakeProvider implements CloudProviderInterface, SupportsPowerControl, SupportsReboot
{
    public const CODE = 'fake';

    public function __construct(private readonly FakeCatalog $catalog) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Fake Provider';
    }

    /**
     * @return list<ProviderLocationData>
     */
    public function getLocations(): array
    {
        return $this->catalog->locations();
    }

    /**
     * @return list<ProviderPlanData>
     */
    public function getPlans(): array
    {
        return $this->catalog->plans();
    }

    /**
     * @return list<ProviderImageData>
     */
    public function getImages(): array
    {
        return $this->catalog->images();
    }

    /**
     * @return list<ProviderPricingData>
     */
    public function getPricing(): array
    {
        return $this->catalog->pricing();
    }

    public function checkAvailability(string $providerPlanId, string $providerLocationId): bool
    {
        return $this->catalog->isAvailable($providerPlanId, $providerLocationId);
    }

    /**
     * Create a server, or return the one this token already made.
     *
     * The order here is the contract. Look the token up first; only create if
     * nothing is found; and treat losing the race on the unique index as
     * another way of finding it, not as an error. A duplicate key here means a
     * concurrent attempt won, and its server is the right answer — creating a
     * second one would bill a customer twice for one order.
     *
     * "Already made" includes servers since deleted. The token is bound to the
     * remote resource for good, so this method can create at most one server
     * per token over the whole life of the system.
     */
    public function createServer(CreateServerRequest $request): ProviderServerData
    {
        $existing = $this->findByProvisioningToken($request->provisioningToken);

        if ($existing instanceof ProviderServerData) {
            // Deliberately returned unchanged, including when it has since been
            // deleted. A retry carrying different parameters must not reshape a
            // server that already exists, and a token that has already produced
            // a server must never produce a second one — the caller learns the
            // outcome from the status rather than receiving a replacement.
            return $existing;
        }

        $this->guardCatalog($request);

        if (! $this->catalog->isAvailable($request->providerPlanId, $request->providerLocationId)) {
            throw ProviderException::outOfStock(
                self::CODE,
                'That plan is not available in that location.',
                ['plan' => $request->providerPlanId, 'location' => $request->providerLocationId],
            );
        }

        $serverId = (string) Str::ulid();

        try {
            // Wrapped in its own transaction so a duplicate key rolls back to a
            // savepoint rather than poisoning the caller's transaction.
            // PostgreSQL aborts a transaction outright on error, and
            // provisioning calls this from inside one, so without this the
            // recovery lookup below could not even run.
            $server = DB::transaction(fn (): FakeProviderServer => FakeProviderServer::query()->create([
                'provider_server_id' => $serverId,
                'provisioning_token' => $request->provisioningToken,
                'name' => $request->name,
                'provider_plan_id' => $request->providerPlanId,
                'provider_location_id' => $request->providerLocationId,
                'provider_image_id' => $request->providerImageId,
                'status' => ProviderServerStatus::Active,
                'power_state' => ProviderPowerState::On,
                'ipv4' => $this->syntheticIpv4($serverId),
                'ipv6' => $this->syntheticIpv6($serverId),
                'metadata' => SafeMetadata::pick($request->labels, array_keys($request->labels))->toArray(),
            ]));
        } catch (QueryException $exception) {
            // The unique index on the token is what actually guarantees one
            // server per order; this is the losing side of that race.
            $winner = $this->findByProvisioningToken($request->provisioningToken);

            if ($winner instanceof ProviderServerData) {
                return $winner;
            }

            throw ProviderException::make(
                ProviderErrorCategory::TransientProviderError,
                self::CODE,
                'The server could not be created.',
                ['reason' => 'persistence_conflict'],
            );
        }

        return $this->toServerData($server);
    }

    public function getServer(string $providerServerId): ProviderServerData
    {
        return $this->toServerData($this->findServerOrFail($providerServerId));
    }

    /**
     * Every server the simulated provider currently holds.
     *
     * Deleted servers are excluded, because a real provider stops listing what
     * it no longer runs, and reconciliation compares against exactly this.
     *
     * @return list<ProviderServerData>
     */
    public function listServers(): array
    {
        $servers = [];

        foreach (FakeProviderServer::query()
            ->where('status', '!=', ProviderServerStatus::Deleted->value)
            ->orderBy('id')
            ->cursor() as $server) {
            if ($server instanceof FakeProviderServer) {
                $servers[] = $this->toServerData($server);
            }
        }

        return $servers;
    }

    /**
     * Delete a server.
     *
     * The row stays, marked deleted, and keeps its provisioning token. The
     * token is a durable correlation identity, not a lease: releasing it would
     * let a later create carrying the same token build a second server, which
     * is exactly the duplicate the token exists to prevent. A customer whose
     * order was already fulfilled and terminated must not be able to receive —
     * and be billed for — a replacement because a retry arrived late.
     *
     * Provisioning a genuinely new server requires a genuinely new token.
     *
     * Deleting one already deleted succeeds and records another action, the way
     * a request to remove something that is gone has already achieved what the
     * caller wanted. Failing here would leave a stuck termination that no
     * retry could ever clear.
     */
    public function deleteServer(string $providerServerId): ProviderActionData
    {
        $server = $this->findServerOrFail($providerServerId);

        $server->forceFill([
            'status' => ProviderServerStatus::Deleted,
            'power_state' => ProviderPowerState::Off,
        ])->save();

        return $this->recordAction('delete', $providerServerId);
    }

    public function getAction(string $providerActionId): ProviderActionData
    {
        $action = FakeProviderAction::query()
            ->where('provider_action_id', $providerActionId)
            ->first();

        if (! $action instanceof FakeProviderAction) {
            throw ProviderException::make(
                ProviderErrorCategory::InvalidRequest,
                self::CODE,
                'No such action.',
                ['action' => $providerActionId],
            );
        }

        return $this->toActionData($action);
    }

    public function findByProvisioningToken(string $provisioningToken): ?ProviderServerData
    {
        $server = FakeProviderServer::query()
            ->where('provisioning_token', $provisioningToken)
            ->first();

        return $server instanceof FakeProviderServer ? $this->toServerData($server) : null;
    }

    public function powerOn(string $providerServerId): ProviderActionData
    {
        return $this->setPowerState($providerServerId, ProviderPowerState::On, 'power_on');
    }

    public function powerOff(string $providerServerId): ProviderActionData
    {
        // Powering off is not proof the provider stopped charging; only
        // deleting is. Nothing here should be read as ending a cost.
        return $this->setPowerState($providerServerId, ProviderPowerState::Off, 'power_off');
    }

    public function reboot(string $providerServerId): ProviderActionData
    {
        $server = $this->findLiveServerOrFail($providerServerId);

        $server->forceFill(['power_state' => ProviderPowerState::On])->save();

        return $this->recordAction('reboot', $providerServerId);
    }

    private function setPowerState(string $providerServerId, ProviderPowerState $state, string $command): ProviderActionData
    {
        $server = $this->findLiveServerOrFail($providerServerId);

        $server->forceFill(['power_state' => $state])->save();

        return $this->recordAction($command, $providerServerId);
    }

    private function guardCatalog(CreateServerRequest $request): void
    {
        $unknown = match (true) {
            ! $this->catalog->hasPlan($request->providerPlanId) => 'plan',
            ! $this->catalog->hasLocation($request->providerLocationId) => 'location',
            ! $this->catalog->hasImage($request->providerImageId) => 'image',
            default => null,
        };

        if ($unknown !== null) {
            // Our request is wrong, so retrying it unchanged cannot help.
            throw ProviderException::invalidRequest(
                self::CODE,
                "Unknown {$unknown} requested.",
                [$unknown => $request->{'provider'.ucfirst($unknown).'Id'}],
            );
        }
    }

    private function findServerOrFail(string $providerServerId): FakeProviderServer
    {
        $server = FakeProviderServer::query()
            ->where('provider_server_id', $providerServerId)
            ->first();

        if (! $server instanceof FakeProviderServer) {
            throw ProviderException::invalidRequest(
                self::CODE,
                'No such server.',
                ['server' => $providerServerId],
            );
        }

        return $server;
    }

    /**
     * A server that still exists remotely.
     *
     * Power operations on a deleted server are refused rather than silently
     * succeeding, so that a caller acting on stale local state finds out.
     */
    private function findLiveServerOrFail(string $providerServerId): FakeProviderServer
    {
        $server = $this->findServerOrFail($providerServerId);

        if (! $server->status->exists()) {
            throw ProviderException::invalidRequest(
                self::CODE,
                'That server has been deleted.',
                ['server' => $providerServerId],
            );
        }

        return $server;
    }

    private function recordAction(string $command, ?string $providerServerId): ProviderActionData
    {
        $now = now();

        // The simulator settles immediately. A real provider returns a running
        // action to poll, which is why the contract returns an action either
        // way rather than a boolean.
        $action = FakeProviderAction::query()->create([
            'provider_action_id' => (string) Str::ulid(),
            'command' => $command,
            'status' => ProviderActionStatus::Success,
            'provider_server_id' => $providerServerId,
            'started_at' => $now,
            'finished_at' => $now,
        ]);

        return $this->toActionData($action);
    }

    private function toServerData(FakeProviderServer $server): ProviderServerData
    {
        return new ProviderServerData(
            providerServerId: $server->provider_server_id,
            provisioningToken: $server->provisioning_token,
            name: (string) $server->name,
            providerPlanId: (string) $server->provider_plan_id,
            providerLocationId: (string) $server->provider_location_id,
            providerImageId: (string) $server->provider_image_id,
            status: $server->status,
            powerState: $server->power_state,
            ipv4: $server->ipv4,
            ipv6: $server->ipv6,
            metadata: SafeMetadata::pick(
                $server->metadata ?? [],
                array_keys($server->metadata ?? []),
            ),
        );
    }

    private function toActionData(FakeProviderAction $action): ProviderActionData
    {
        return new ProviderActionData(
            providerActionId: $action->provider_action_id,
            command: (string) $action->command,
            status: $action->status,
            providerServerId: $action->provider_server_id,
            startedAt: new DateTimeImmutable($action->started_at->toIso8601String()),
            finishedAt: $action->finished_at?->toDateTimeImmutable(),
            metadata: SafeMetadata::pick(
                $action->metadata ?? [],
                array_keys($action->metadata ?? []),
            ),
        );
    }

    /**
     * Deterministic documentation-range address, derived from the server id.
     *
     * From 198.51.100.0/24, reserved by RFC 5737 for examples, so nothing here
     * can be mistaken for or routed to a real host.
     */
    private function syntheticIpv4(string $serverId): string
    {
        return '198.51.100.'.(hexdec(substr(hash('sha256', $serverId), 0, 4)) % 254 + 1);
    }

    /**
     * From 2001:db8::/32, the RFC 3849 documentation range.
     */
    private function syntheticIpv6(string $serverId): string
    {
        return '2001:db8::'.substr(hash('sha256', $serverId), 0, 4);
    }
}
