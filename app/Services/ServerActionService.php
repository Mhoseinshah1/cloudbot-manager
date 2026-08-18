<?php

namespace App\Services;

use App\Contracts\CloudProviderInterface;
use App\Contracts\Data\ProviderImageData;
use App\Exceptions\ProviderException;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Throwable;

/**
 * Executes lifecycle/power actions on a server through the provider adapter.
 *
 * - authorizes local ownership before any provider call
 * - validates the provider capability before acting
 * - persists a server_actions audit record for every attempt
 * - never logs the root password; it is returned once for authorized delivery
 *
 * Deletion is only ever triggered by an explicit, authorized request — never
 * by an expiration timestamp (that is lifecycle-policy territory, Phase 5).
 */
class ServerActionService
{
    public function __construct(
        private ProviderManager $manager,
        private AuditService $audit,
        private HourlyBillingService $billing,
    ) {}

    /**
     * @return array{action: ServerAction, password: ?string}
     */
    public function perform(Server $server, string $action, ?User $actor = null, ?ProviderImageData $image = null): array
    {
        $this->authorize($server, $actor);
        $this->assertKnownAction($action);

        /** @var Provider|null $provider */
        $provider = $server->provider;

        if ($provider === null) {
            throw new ProviderException('Server has no provider attached.');
        }

        $capability = $this->capabilityFor($action);

        if ($capability !== null && ! $provider->supports($capability)) {
            throw ProviderException::unavailable('Action', $action);
        }

        if ($action === ServerAction::ACTION_REBUILD && $image === null) {
            throw new InvalidArgumentException('Rebuild requires an explicit image.');
        }

        $adapter = $this->manager->resolve($provider);

        $record = ServerAction::query()->create([
            'server_id' => $server->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'status' => ServerAction::STATUS_PENDING,
        ]);

        $password = null;

        try {
            match ($action) {
                ServerAction::ACTION_POWER_ON => $this->powerOn($adapter, $server),
                ServerAction::ACTION_POWER_OFF => $this->powerOff($adapter, $server),
                ServerAction::ACTION_REBOOT => $adapter->reboot($server->provider_server_id),
                ServerAction::ACTION_REBUILD => $this->rebuild($adapter, $server, $image),
                ServerAction::ACTION_RESET_PASSWORD => $password = $this->resetPassword($adapter, $server),
                ServerAction::ACTION_DELETE => $this->delete($adapter, $server),
                default => throw new InvalidArgumentException("Unknown server action [{$action}]."),
            };

            $record->update(['status' => ServerAction::STATUS_COMPLETED]);

            $this->audit->record("server.{$action}", $server, $actor, after: [
                'provider_server_id' => $server->provider_server_id,
                'action' => $action,
                'status' => ServerAction::STATUS_COMPLETED,
            ]);

            return ['action' => $record->fresh(), 'password' => $password];
        } catch (Throwable $e) {
            $record->update([
                'status' => ServerAction::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            $this->audit->record("server.{$action}.failed", $server, $actor, after: [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function authorize(Server $server, ?User $actor): void
    {
        if ($actor === null) {
            return; // system-initiated (explicit lifecycle/reconciliation code)
        }

        if ($actor->isAdmin() || $actor->id === $server->user_id) {
            return;
        }

        throw new AuthorizationException('You are not allowed to act on this server.');
    }

    private function assertKnownAction(string $action): void
    {
        $known = [
            ServerAction::ACTION_POWER_ON,
            ServerAction::ACTION_POWER_OFF,
            ServerAction::ACTION_REBOOT,
            ServerAction::ACTION_REBUILD,
            ServerAction::ACTION_RESET_PASSWORD,
            ServerAction::ACTION_DELETE,
        ];

        if (! in_array($action, $known, true)) {
            throw new InvalidArgumentException("Unknown server action [{$action}].");
        }
    }

    private function capabilityFor(string $action): ?string
    {
        return match ($action) {
            ServerAction::ACTION_POWER_ON => 'supportsPowerOn',
            ServerAction::ACTION_POWER_OFF => 'supportsPowerOff',
            ServerAction::ACTION_REBOOT => 'supportsReboot',
            ServerAction::ACTION_REBUILD => 'supportsRebuild',
            ServerAction::ACTION_RESET_PASSWORD => 'supportsResetPassword',
            default => null, // delete is required on every adapter
        };
    }

    private function powerOn(CloudProviderInterface $adapter, Server $server): void
    {
        $adapter->powerOn($server->provider_server_id);
        $server->update([
            'power_state' => 'running',
            'status' => Server::STATUS_RUNNING,
        ]);
    }

    private function powerOff(CloudProviderInterface $adapter, Server $server): void
    {
        $adapter->powerOff($server->provider_server_id);
        $server->update([
            'power_state' => 'off',
            'status' => Server::STATUS_OFF,
        ]);
    }

    private function rebuild(CloudProviderInterface $adapter, Server $server, ProviderImageData $image): void
    {
        $adapter->rebuild($server->provider_server_id, $image);
        $server->update([
            'image_snapshot' => $image->toArray(),
            'status' => Server::STATUS_RUNNING,
            'power_state' => 'running',
        ]);
    }

    private function resetPassword(CloudProviderInterface $adapter, Server $server): string
    {
        $password = $adapter->resetPassword($server->provider_server_id);

        // Stored encrypted immediately; delivered once to the caller.
        $server->update(['root_password_encrypted' => $password]);

        return $password;
    }

    private function delete(CloudProviderInterface $adapter, Server $server): void
    {
        $adapter->deleteServer($server->provider_server_id);

        // Hourly billing stops here — the final partial hour is settled per
        // the configured rounding policy. Power state changes never do this.
        $this->billing->stopBilling($server);

        $server->update(['status' => Server::STATUS_DELETED]);
        $server->delete(); // soft delete preserves history
    }
}
