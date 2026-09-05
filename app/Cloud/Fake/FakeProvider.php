<?php

declare(strict_types=1);

namespace App\Cloud\Fake;

use App\Cloud\Capabilities\SupportsPasswordReset;
use App\Cloud\Capabilities\SupportsPowerControl;
use App\Cloud\Capabilities\SupportsReboot;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Data\ProviderCreateResult;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPasswordResetData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Data\SafeMetadata;
use App\Cloud\Data\SensitiveRootCredential;
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
final class FakeProvider implements CloudProviderInterface, SupportsPasswordReset, SupportsPowerControl, SupportsReboot
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
    public function createServer(CreateServerRequest $request): ProviderCreateResult
    {
        $existing = $this->findByProvisioningToken($request->provisioningToken);

        if ($existing instanceof ProviderServerData) {
            // Deliberately returned unchanged, including when it has since been
            // deleted. A retry carrying different parameters must not reshape a
            // server that already exists, and a token that has already produced
            // a server must never produce a second one — the caller learns the
            // outcome from the status rather than receiving a replacement.
            //
            // And with no credential. A one-time password is issued once; a
            // provider replaying an earlier result has none left to give, and
            // pretending otherwise would let a caller believe a credential it
            // never received is safely in hand.
            return ProviderCreateResult::withoutCredential($existing);
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

        // Issued once, on creation, exactly as a password-authenticating
        // provider does. Generated at runtime — nothing credential-shaped is
        // ever written into this repository.
        $password = self::issuePassword();

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
                'root_password_verifier' => self::verifier($password),
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
                // The losing side of the token race. The winner issued the
                // password, and this caller never held it.
                return ProviderCreateResult::withoutCredential($winner);
            }

            throw ProviderException::make(
                ProviderErrorCategory::TransientProviderError,
                self::CODE,
                'The server could not be created.',
                ['reason' => 'persistence_conflict'],
            );
        }

        // The one and only moment this provider hands a credential over.
        return new ProviderCreateResult(
            $this->toServerData($server),
            new SensitiveRootCredential($password),
        );
    }

    /**
     * Issue a new root password for a server this provider already runs.
     *
     * The old password stops working, exactly as a real provider's reset does.
     * That is what makes it safe for pre-delivery recovery and unsafe for
     * anything else: rotating a credential nobody holds costs nothing, and
     * rotating one a customer is using locks them out.
     */
    public function resetRootPassword(string $providerServerId): ProviderPasswordResetData
    {
        $server = $this->findServerOrFail($providerServerId);

        if ($server->status === ProviderServerStatus::Deleted) {
            throw ProviderException::invalidRequest(
                self::CODE,
                'That server has been deleted.',
                ['server' => $providerServerId],
            );
        }

        $password = self::issuePassword();

        $server->forceFill(['root_password_verifier' => self::verifier($password)])->save();

        $action = $this->recordAction('reset_password', $providerServerId);

        return new ProviderPasswordResetData(
            providerActionId: $action->providerActionId,
            providerServerId: $providerServerId,
            status: $action->status,
            rootCredential: new SensitiveRootCredential($password),
            // Never the password. This is the surface everything else reads.
            metadata: SafeMetadata::pick(['command' => 'reset_password'], ['command']),
        );
    }

    /**
     * Whether this credential is the one this server currently accepts.
     *
     * The simulator's equivalent of trying to log in, and the only way anything
     * can learn what password this provider holds: by presenting one and being
     * told yes or no. There is deliberately no method that hands the plaintext
     * back, because the provider does not keep it — only a digest of it.
     *
     * A rotation therefore makes the previous credential stop matching, which
     * is exactly what a real reset does and what a plaintext column could only
     * pretend to model.
     */
    public function credentialMatches(string $providerServerId, SensitiveRootCredential|string $credential): bool
    {
        $stored = FakeProviderServer::query()
            ->where('provider_server_id', $providerServerId)
            ->value('root_password_verifier');

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        $plaintext = $credential instanceof SensitiveRootCredential ? $credential->reveal() : $credential;

        // Constant time, because comparing digests with === is the habit that
        // eventually gets copied somewhere it matters.
        return hash_equals($stored, self::verifier($plaintext));
    }

    /**
     * A password this simulator issues, generated per call.
     *
     * Random rather than derived, so no test can pass by predicting it, and
     * runtime-only, so no credential-shaped literal is committed.
     */
    private static function issuePassword(): string
    {
        return 'fpw-'.bin2hex(random_bytes(16));
    }

    /**
     * The one-way value this provider keeps instead of a password.
     *
     * SHA-256 without a salt or a slow KDF, deliberately. The input is 128 bits
     * of `random_bytes`, so there is no guessing, dictionary or rainbow-table
     * exposure for a work factor to defend against — and a deliberately slow
     * hash would cost every test in the suite for security this value does not
     * need. What matters here is only that it is irreversible.
     */
    private static function verifier(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * One server, or null when this provider has never heard of it.
     *
     * Null rather than a refusal, because "no such server" is an answer and a
     * refusal is not. A deleted server still answers with its tombstone: it
     * existed, we know what became of it, and reconciliation needs to be able
     * to tell that apart from an identity that was never real.
     */
    public function getServer(string $providerServerId): ?ProviderServerData
    {
        $server = FakeProviderServer::query()
            ->where('provider_server_id', $providerServerId)
            ->first();

        return $server instanceof FakeProviderServer ? $this->toServerData($server) : null;
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
