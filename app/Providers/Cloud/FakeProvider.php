<?php

namespace App\Providers\Cloud;

use App\Contracts\CloudProviderInterface;
use App\Contracts\Data\ProviderActionData;
use App\Contracts\Data\ProviderImageData;
use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPlanData;
use App\Contracts\Data\ProviderPricingData;
use App\Contracts\Data\ProviderServerData;
use App\Contracts\Data\ProviderUsageData;
use App\Exceptions\ProviderException;

/**
 * Deterministic, zero-network provider adapter used for automated tests
 * and local development. Never talks to a real cloud provider.
 */
class FakeProvider implements CloudProviderInterface
{
    /** @var array<string, ProviderServerData> */
    private array $servers = [];

    /** @var array<string, ProviderActionData> */
    private array $actions = [];

    private int $sequence = 0;

    private int $actionSequence = 0;

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $options  fail_create: true to simulate a provisioning failure
     */
    public function __construct(array $credentials = [], private array $options = [])
    {
        if ($credentials !== [] && ! isset($credentials['token'])) {
            throw new \InvalidArgumentException('FakeProvider expects a "token" credential when credentials are provided.');
        }
    }

    public function code(): string
    {
        return 'fake';
    }

    public function name(): string
    {
        return 'Fake Provider';
    }

    public function capabilities(): array
    {
        return [
            'supportsPowerOn' => true,
            'supportsPowerOff' => true,
            'supportsReboot' => true,
            'supportsRebuild' => true,
            'supportsResetPassword' => true,
            'supportsSnapshots' => false,
            'supportsSuspend' => false,
            'supportsUsage' => true,
        ];
    }

    public function getLocations(): array
    {
        return [
            new ProviderLocationData('fsn1', 'Falkenstein', 'DE', 'Falkenstein'),
            new ProviderLocationData('nbg1', 'Nuremberg', 'DE', 'Nuremberg'),
            new ProviderLocationData('hel1', 'Helsinki', 'FI', 'Helsinki'),
        ];
    }

    public function getPlans(): array
    {
        return [
            new ProviderPlanData('cpx11', 'CX11', 1, 2048, 40, 20000, 4.50, 'EUR', 0.006),
            new ProviderPlanData('cpx21', 'CX21', 2, 4096, 80, 20000, 8.50, 'EUR', 0.012),
            new ProviderPlanData('cpx31', 'CX31', 4, 8192, 160, 20000, 15.50, 'EUR', 0.022),
        ];
    }

    public function getImages(): array
    {
        return [
            new ProviderImageData('ubuntu-24.04', 'Ubuntu 24.04', 'linux', 'ubuntu', '24.04', 'x86'),
            new ProviderImageData('debian-12', 'Debian 12', 'linux', 'debian', '12', 'x86'),
            new ProviderImageData('centos-9', 'CentOS Stream 9', 'linux', 'centos', '9', 'x86'),
        ];
    }

    public function getPricing(): array
    {
        $pricing = [];

        foreach ($this->getPlans() as $plan) {
            foreach ($this->getLocations() as $location) {
                $pricing[] = new ProviderPricingData(
                    serverTypeId: $plan->id,
                    locationId: $location->id,
                    currency: $plan->currency,
                    priceHourly: $plan->priceHourly,
                    priceMonthly: $plan->priceMonthly,
                    includedTraffic: $plan->bandwidthGb !== null ? $plan->bandwidthGb * (1024 ** 3) : null,
                );
            }
        }

        return $pricing;
    }

    public function createServer(
        ProviderPlanData $plan,
        ProviderImageData $image,
        ProviderLocationData $location,
        string $name,
        array $options = []
    ): ProviderServerData {
        if ($this->options['fail_create'] ?? false) {
            throw ProviderException::insufficientBalance('Insufficient balance in the fake provider account.');
        }

        $this->sequence++;
        $id = 'fake-'.$this->sequence;

        $server = new ProviderServerData(
            id: $id,
            name: $name,
            status: 'running',
            ipAddress: '10.0.0.'.$this->sequence,
            rootPassword: 'F4keP@ss!'.$this->sequence,
            locationId: $location->id,
            planId: $plan->id,
            imageId: $image->id,
            metadata: ['labels' => $options['labels'] ?? []],
        );

        $this->servers[$id] = $server;

        return $server;
    }

    public function getServer(string $providerServerId): ProviderServerData
    {
        if (! isset($this->servers[$providerServerId])) {
            throw ProviderException::unavailable('Server', $providerServerId);
        }

        return $this->servers[$providerServerId];
    }

    public function powerOn(string $providerServerId): ProviderActionData
    {
        $this->mustExist($providerServerId);

        return $this->completeAction('start_server', $providerServerId);
    }

    public function powerOff(string $providerServerId): ProviderActionData
    {
        $this->mustExist($providerServerId);

        return $this->completeAction('stop_server', $providerServerId);
    }

    public function reboot(string $providerServerId): ProviderActionData
    {
        $this->mustExist($providerServerId);

        return $this->completeAction('reboot_server', $providerServerId);
    }

    public function rebuild(string $providerServerId, ProviderImageData $image): ProviderActionData
    {
        $this->mustExist($providerServerId);

        return $this->completeAction('rebuild_server', $providerServerId);
    }

    public function resetPassword(string $providerServerId): string
    {
        $this->mustExist($providerServerId);

        return 'N3wP@ss!'.random_int(1000, 9999);
    }

    public function deleteServer(string $providerServerId): void
    {
        $this->mustExist($providerServerId);
        unset($this->servers[$providerServerId]);
    }

    public function getUsage(string $providerServerId): ProviderUsageData
    {
        $this->mustExist($providerServerId);

        return new ProviderUsageData(cpuPercent: 12.5, ramMb: 1024, diskGb: 8.2, bandwidthGb: 120);
    }

    public function getAction(string $actionId): ProviderActionData
    {
        if (! isset($this->actions[$actionId])) {
            throw ProviderException::unavailable('Action', $actionId);
        }

        return $this->actions[$actionId];
    }

    public function waitForAction(string $actionId, int $timeoutSeconds = 300, int $pollingIntervalMs = 2000): ProviderActionData
    {
        // Fake actions complete synchronously; nothing to poll.
        return $this->getAction($actionId);
    }

    public function findServerByLabel(string $key, string $value): ?ProviderServerData
    {
        foreach ($this->servers as $server) {
            $labels = $server->metadata['labels'] ?? [];

            if (($labels[$key] ?? null) === $value) {
                return $server;
            }
        }

        return null;
    }

    /**
     * Records and returns a synchronously-completed provider action.
     */
    private function completeAction(string $command, string $serverId): ProviderActionData
    {
        $this->actionSequence++;
        $id = 'action-'.$this->actionSequence;

        $action = new ProviderActionData(
            id: $id,
            command: $command,
            status: ProviderActionData::STATUS_SUCCESS,
            progress: 100,
            serverId: $serverId,
        );

        $this->actions[$id] = $action;

        return $action;
    }

    private function mustExist(string $providerServerId): void
    {
        if (! isset($this->servers[$providerServerId])) {
            throw ProviderException::unavailable('Server', $providerServerId);
        }
    }
}
