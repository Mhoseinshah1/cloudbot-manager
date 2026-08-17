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
use App\Exceptions\ProviderApiException;
use App\Integrations\Hetzner\HetznerApiClient;

/**
 * Production Hetzner Cloud adapter (API v1).
 *
 * Talks to the Hetzner API exclusively through HetznerApiClient and
 * normalizes every response into the shared provider Data/Value Objects.
 * Raw Hetzner response structures never leave this adapter.
 *
 * The token comes from the encrypted provider_credentials table (or the
 * HETZNER_API_TOKEN env fallback) and is never logged or exposed.
 */
class HetznerProvider implements CloudProviderInterface
{
    private ?HetznerApiClient $client = null;

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $options  base_url, timeout, connect_timeout, retry_*
     */
    public function __construct(
        private array $credentials = [],
        private array $options = [],
    ) {}

    public function code(): string
    {
        return 'hetzner';
    }

    public function name(): string
    {
        return 'Hetzner Cloud';
    }

    public function capabilities(): array
    {
        return [
            'supportsPowerOn' => true,
            'supportsPowerOff' => true,
            'supportsReboot' => true,
            'supportsRebuild' => true,
            'supportsResetPassword' => true,
            'supportsSnapshots' => true, // Hetzner offers snapshots via its API
            'supportsSuspend' => false,  // no provider-neutral suspend equivalent
            'supportsUsage' => true,     // GET /servers/{id}/metrics?type=cpu
        ];
    }

    public function getLocations(): array
    {
        $locations = $this->client()->getAll('/locations', 'locations');

        return array_map(fn (array $loc): ProviderLocationData => new ProviderLocationData(
            id: (string) ($loc['name'] ?? ''),
            name: (string) ($loc['description'] ?? $loc['name'] ?? ''),
            countryCode: $loc['country'] ?? null,
            city: $loc['city'] ?? null,
            metadata: [
                'network_zone' => $loc['network_zone'] ?? null,
                'latitude' => $loc['latitude'] ?? null,
                'longitude' => $loc['longitude'] ?? null,
            ],
        ), $locations);
    }

    public function getPlans(): array
    {
        $types = $this->client()->getAll('/server_types', 'server_types');

        return array_map(function (array $type): ProviderPlanData {
            $firstPrice = $type['prices'][0] ?? [];

            return new ProviderPlanData(
                id: (string) ($type['name'] ?? ''),
                name: (string) ($type['description'] ?? $type['name'] ?? ''),
                vcpu: (int) ($type['cores'] ?? 0),
                ramMb: (int) round(((float) ($type['memory'] ?? 0)) * 1024),
                diskGb: (int) ($type['disk'] ?? 0),
                // included_traffic lives per-location on the price entries (current schema).
                bandwidthGb: $this->bytesToGb($firstPrice['included_traffic'] ?? null),
                priceMonthly: (float) ($firstPrice['price_monthly']['net'] ?? 0),
                currency: 'EUR', // project currency comes from the /pricing sync
                priceHourly: isset($firstPrice['price_hourly']['net']) ? (float) $firstPrice['price_hourly']['net'] : null,
                description: $type['description'] ?? null,
                metadata: [
                    'id' => $type['id'] ?? null,
                    'cpu_type' => $type['cpu_type'] ?? null,
                    'architecture' => $type['architecture'] ?? null,
                    'storage_type' => $type['storage_type'] ?? null,
                    'deprecated' => $type['deprecated'] ?? false,
                    'deprecation' => $type['deprecation'] ?? null,
                    // Per-location availability (post 2025-09-24 schema).
                    'locations' => $type['locations'] ?? [],
                    'prices' => $type['prices'] ?? [],
                ],
            );
        }, $types);
    }

    public function getPricing(): array
    {
        $response = $this->client()->get('/pricing');
        $pricing = $response['pricing'] ?? [];
        $currency = (string) ($pricing['currency'] ?? 'EUR');

        $rows = [];

        foreach ($pricing['server_types'] ?? [] as $type) {
            foreach ($type['prices'] ?? [] as $price) {
                $rows[] = new ProviderPricingData(
                    serverTypeId: (string) ($type['name'] ?? ''),
                    locationId: (string) ($price['location'] ?? ''),
                    currency: $currency,
                    priceHourly: isset($price['price_hourly']['net']) ? (float) $price['price_hourly']['net'] : null,
                    priceMonthly: isset($price['price_monthly']['net']) ? (float) $price['price_monthly']['net'] : null,
                    includedTraffic: $price['included_traffic'] ?? null,
                    pricePerTbTraffic: isset($price['price_per_tb_traffic']['net']) ? (float) $price['price_per_tb_traffic']['net'] : null,
                );
            }
        }

        return $rows;
    }

    public function getImages(): array
    {
        // System images only; include deprecated so the sync can mark them.
        $images = $this->client()->getAll('/images', 'images', [
            'type' => 'system',
            'include_deprecated' => 'true',
        ]);

        return array_map(fn (array $image): ProviderImageData => new ProviderImageData(
            id: (string) ($image['id'] ?? ''),
            name: (string) ($image['description'] ?? $image['name'] ?? ''),
            osFamily: $this->osFamily((string) ($image['os_flavor'] ?? '')),
            osDistro: $image['os_flavor'] ?? null,
            version: $image['os_version'] ?? null,
            architecture: $image['architecture'] ?? null,
            metadata: [
                'type' => $image['type'] ?? null,
                'status' => $image['status'] ?? null,
                'deprecated' => $image['deprecated'] ?? null,
                'image_size' => $image['image_size'] ?? null,
                'disk_size' => $image['disk_size'] ?? null,
            ],
        ), $images);
    }

    public function createServer(
        ProviderPlanData $plan,
        ProviderImageData $image,
        ProviderLocationData $location,
        string $name,
        array $options = []
    ): ProviderServerData {
        $response = $this->client()->post('/servers', [
            'name' => $name,
            'server_type' => $plan->id,
            'image' => (int) $image->id,
            'location' => $location->id,
            'labels' => $options['labels'] ?? [],
            'start_after_create' => $options['start_after_create'] ?? true,
        ]);

        $server = $response['server'] ?? [];
        $action = $response['action'] ?? [];

        $serverData = $this->normalizeServer($server);

        // The root password is returned exactly once at creation. The caller
        // must encrypt it immediately; it is never logged here.
        $serverData->rootPassword = $response['root_password'] ?? null;

        // HTTP success is never treated as final provider success: when the
        // provider reports an asynchronous create action, wait for it and
        // then confirm the authoritative server state.
        if (isset($action['id']) && $serverData->id !== '') {
            $this->awaitAction($action, 'create_server');

            $confirmed = $this->getServer($serverData->id);
            $confirmed->rootPassword = $serverData->rootPassword;
            $serverData = $confirmed;
        }

        $serverData->action = $this->normalizeAction($action);

        return $serverData;
    }

    public function getServer(string $providerServerId): ProviderServerData
    {
        $response = $this->client()->get("/servers/{$providerServerId}");

        return $this->normalizeServer($response['server'] ?? []);
    }

    public function powerOn(string $providerServerId): ProviderActionData
    {
        return $this->runAction($providerServerId, 'poweron');
    }

    public function powerOff(string $providerServerId): ProviderActionData
    {
        return $this->runAction($providerServerId, 'poweroff');
    }

    public function reboot(string $providerServerId): ProviderActionData
    {
        return $this->runAction($providerServerId, 'reboot');
    }

    public function rebuild(string $providerServerId, ProviderImageData $image): ProviderActionData
    {
        return $this->runAction($providerServerId, 'rebuild', ['image' => (int) $image->id]);
    }

    public function resetPassword(string $providerServerId): string
    {
        $response = $this->client()->post("/servers/{$providerServerId}/actions/reset_password");

        $action = $response['action'] ?? [];
        $this->awaitAction($action, 'reset_password');

        $password = $response['root_password'] ?? null;

        if ($password === null) {
            throw new ProviderApiException('Hetzner API did not return a root password for the reset action.');
        }

        return $password;
    }

    public function deleteServer(string $providerServerId): void
    {
        $this->client()->delete("/servers/{$providerServerId}");
    }

    public function getUsage(string $providerServerId): ProviderUsageData
    {
        $response = $this->client()->get("/servers/{$providerServerId}/metrics", ['type' => 'cpu']);

        $values = $response['metrics']['time_series']['cpu']['values'] ?? [];

        $cpuPercent = 0.0;
        if (count($values) > 0) {
            $last = $values[count($values) - 1];
            $cpuPercent = (float) ($last[1] ?? 0);
        }

        // Hetzner metrics only cover CPU/disk/network utilization; there is no
        // bandwidth accounting here, so bandwidthGb stays null.
        return new ProviderUsageData(
            cpuPercent: $cpuPercent,
            ramMb: 0.0,
            diskGb: 0.0,
            bandwidthGb: null,
            metadata: [
                'metric_types' => ['cpu'],
                'series_points' => count($values),
            ],
        );
    }

    /**
     * Searches servers by a label selector — used by reconciliation to detect
     * whether a server was already created before a create call is retried.
     */
    public function findServerByLabel(string $key, string $value): ?ProviderServerData
    {
        $response = $this->client()->get('/servers', [
            'label_selector' => "{$key}={$value}",
        ]);

        $server = $response['servers'][0] ?? null;

        return $server !== null ? $this->normalizeServer($server) : null;
    }

    public function getAction(string $actionId): ProviderActionData
    {
        $response = $this->client()->getAction((int) $actionId);

        return $this->normalizeAction($response['action'] ?? []);
    }

    public function waitForAction(string $actionId, int $timeoutSeconds = 300, int $pollingIntervalMs = 2000): ProviderActionData
    {
        $action = $this->client()->waitForAction((int) $actionId, $timeoutSeconds, $pollingIntervalMs);

        return $this->normalizeAction($action);
    }

    private function client(): HetznerApiClient
    {
        return $this->client ??= new HetznerApiClient(
            token: (string) ($this->credentials['token'] ?? config('services.hetzner.token', '')),
            options: $this->options,
        );
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function normalizeServer(array $server): ProviderServerData
    {
        return new ProviderServerData(
            id: (string) ($server['id'] ?? ''),
            name: (string) ($server['name'] ?? ''),
            status: (string) ($server['status'] ?? 'unknown'),
            ipAddress: $server['public_net']['ipv4']['ip'] ?? null,
            locationId: $server['datacenter']['location']['name'] ?? null,
            planId: $server['server_type']['name'] ?? null,
            imageId: isset($server['image']['id']) ? (string) $server['image']['id'] : null,
            metadata: [
                'ipv6' => $server['public_net']['ipv6']['ip'] ?? null,
                'created' => $server['created'] ?? null,
                'labels' => $server['labels'] ?? [],
                'protection' => $server['protection'] ?? [],
            ],
        );
    }

    /**
     * Executes a server action and waits for its provider action to reach a
     * terminal state. Never marks an operation successful while the provider
     * action still reports "running".
     *
     * @param  array<string, mixed>  $payload
     */
    private function runAction(string $providerServerId, string $command, array $payload = []): ProviderActionData
    {
        $response = $this->client()->post("/servers/{$providerServerId}/actions/{$command}", $payload);

        return $this->awaitAction($response['action'] ?? [], $command);
    }

    /**
     * Waits on an action snapshot until it reaches success/error.
     *
     * @param  array<string, mixed>  $action
     */
    private function awaitAction(array $action, string $command): ProviderActionData
    {
        $status = (string) ($action['status'] ?? 'running');
        $actionId = (int) ($action['id'] ?? 0);

        if ($status === ProviderActionData::STATUS_SUCCESS) {
            return $this->normalizeAction($action);
        }

        if ($status === ProviderActionData::STATUS_ERROR) {
            throw $this->actionError($action, $command);
        }

        if ($actionId <= 0) {
            throw new ProviderApiException("Hetzner action [{$command}] is running but has no action id to poll.");
        }

        $final = $this->waitForAction((string) $actionId);

        if ($final->status === ProviderActionData::STATUS_ERROR) {
            throw $this->actionError($final->toArray(), $command);
        }

        return $final;
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function actionError(array $action, string $command): ProviderApiException
    {
        $error = $action['error'] ?? [];
        $message = (string) ($error['message'] ?? 'unknown error');

        return new ProviderApiException("Hetzner action [{$command}] failed: {$message}");
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function normalizeAction(array $action): ProviderActionData
    {
        $resources = $action['resources'] ?? [];
        $serverId = null;

        foreach ($resources as $resource) {
            if (($resource['type'] ?? null) === 'server') {
                $serverId = (string) ($resource['id'] ?? '');
                break;
            }
        }

        return new ProviderActionData(
            id: (string) ($action['id'] ?? ''),
            command: (string) ($action['command'] ?? ''),
            status: (string) ($action['status'] ?? 'running'),
            progress: (int) ($action['progress'] ?? 0),
            started: $action['started'] ?? null,
            finished: $action['finished'] ?? null,
            error: $action['error'] ?? null,
            serverId: $serverId,
        );
    }

    private function bytesToGb(?int $bytes): ?int
    {
        if ($bytes === null) {
            return null;
        }

        return (int) floor($bytes / (1024 ** 3));
    }

    private function osFamily(string $osFlavor): ?string
    {
        $flavor = strtolower($osFlavor);

        return str_contains($flavor, 'windows') ? 'windows' : ($flavor !== '' ? 'linux' : null);
    }
}
