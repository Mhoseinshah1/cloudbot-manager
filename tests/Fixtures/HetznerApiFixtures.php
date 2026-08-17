<?php

namespace Tests\Fixtures;

/**
 * Realistic Hetzner Cloud API v1 payloads for mocked tests.
 *
 * Mirror the current official schemas (locations, server_types with
 * per-location availability/deprecation, /pricing, images, servers, actions,
 * metrics, error envelope) so tests never depend on network access and stay
 * valid as the API evolves.
 */
class HetznerApiFixtures
{
    public const BASE_URL = 'https://api.hetzner.test/v1';

    public const TOKEN = 'sekrit-hetzner-token';

    // ------------------------------------------------------------------
    // Locations
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function location(string $name, string $description, string $country, string $city, string $networkZone, float $lat, float $lon): array
    {
        return [
            'id' => crc32($name),
            'name' => $name,
            'description' => $description,
            'country' => $country,
            'city' => $city,
            'latitude' => $lat,
            'longitude' => $lon,
            'network_zone' => $networkZone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function locationsResponse(int $page = 1, int $perPage = 50): array
    {
        $all = [
            self::location('fsn1', 'Falkenstein DC Park 1', 'DE', 'Falkenstein', 'eu-central', 50.47612, 12.370071),
            self::location('nbg1', 'Nuremberg DC Park 1', 'DE', 'Nuremberg', 'eu-central', 49.452102, 11.076665),
            self::location('hel1', 'Helsinki DC Park 1', 'FI', 'Helsinki', 'eu-central', 60.169855, 24.938379),
            self::location('ash', 'Ashburn DC Park 1', 'US', 'Ashburn', 'us-east', 39.043757, -77.487442),
            self::location('hil', 'Hillsboro DC Park 1', 'US', 'Hillsboro', 'us-west', 45.523064, -122.989403),
        ];

        $chunk = array_slice($all, ($page - 1) * $perPage, $perPage);

        return [
            'locations' => $chunk,
            'meta' => ['pagination' => self::pagination($page, $perPage, count($all))],
        ];
    }

    // ------------------------------------------------------------------
    // Server types
    // ------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $locations  per-location availability entries
     * @param  array<int, array<string, mixed>>  $prices  per-location price entries
     * @return array<string, mixed>
     */
    public static function serverType(
        int $id,
        string $name,
        string $description,
        int $cores,
        float $memoryGb,
        int $diskGb,
        string $cpuType,
        string $architecture,
        string $storageType,
        array $locations,
        array $prices,
        bool $deprecated = false,
        ?array $deprecation = null,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'cores' => $cores,
            'memory' => $memoryGb,
            'disk' => $diskGb,
            'deprecated' => $deprecated,
            'deprecation' => $deprecation,
            'prices' => $prices,
            'server_type' => $name,
            'cpu_type' => $cpuType,
            'architecture' => $architecture,
            'storage_type' => $storageType,
            'locations' => $locations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serverTypePrice(string $location, string $hourlyNet, string $monthlyNet, int $includedTrafficBytes, string $trafficPerTbNet = '1.0'): array
    {
        return [
            'location' => $location,
            'price_hourly' => ['net' => $hourlyNet, 'gross' => number_format((float) $hourlyNet * 1.19, 6)],
            'price_monthly' => ['net' => $monthlyNet, 'gross' => number_format((float) $monthlyNet * 1.19, 4)],
            'included_traffic' => $includedTrafficBytes,
            'price_per_tb_traffic' => ['net' => $trafficPerTbNet, 'gross' => number_format((float) $trafficPerTbNet * 1.19, 2)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serverTypeAvailability(string $location, ?array $deprecation = null): array
    {
        return ['location' => $location, 'deprecation' => $deprecation];
    }

    public const TB = 1_099_511_627_776; // 1 TiB in bytes

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function serverTypes(): array
    {
        $eu = ['fsn1', 'nbg1', 'hel1'];
        $us = ['ash', 'hil'];

        return [
            self::serverType(
                id: 1,
                name: 'cx22',
                description: 'CX22',
                cores: 2,
                memoryGb: 4.0,
                diskGb: 40,
                cpuType: 'shared',
                architecture: 'x86',
                storageType: 'local',
                locations: [
                    ...array_map(fn (string $l): array => self::serverTypeAvailability($l), $eu),
                    self::serverTypeAvailability('ash'),
                    self::serverTypeAvailability('hil', ['unavailable_after' => '2026-09-01T00:00:00+00:00', 'unavailable' => true]),
                ],
                prices: [
                    ...array_map(fn (string $l): array => self::serverTypePrice($l, '0.0078', '4.29', 20 * self::TB), $eu),
                    self::serverTypePrice('ash', '0.0080', '4.40', 20 * self::TB),
                    self::serverTypePrice('hil', '0.0080', '4.40', 20 * self::TB),
                ],
            ),
            self::serverType(
                id: 2,
                name: 'cpx21',
                description: 'CPX21',
                cores: 3,
                memoryGb: 4.0,
                diskGb: 80,
                cpuType: 'shared',
                architecture: 'arm',
                storageType: 'local',
                locations: array_map(fn (string $l): array => self::serverTypeAvailability($l), $eu),
                prices: array_map(fn (string $l): array => self::serverTypePrice($l, '0.0076', '4.19', 20 * self::TB), $eu),
            ),
            self::serverType(
                id: 3,
                name: 'ccx23',
                description: 'CCX23',
                cores: 4,
                memoryGb: 8.0,
                diskGb: 80,
                cpuType: 'dedicated',
                architecture: 'x86',
                storageType: 'local',
                locations: array_map(fn (string $l): array => self::serverTypeAvailability($l), [...$eu, ...$us]),
                prices: array_map(fn (string $l): array => self::serverTypePrice($l, '0.0274', '15.06', 2 * self::TB), [...$eu, ...$us]),
            ),
            self::serverType(
                id: 4,
                name: 'cx32',
                description: 'CX32',
                cores: 4,
                memoryGb: 8.0,
                diskGb: 80,
                cpuType: 'shared',
                architecture: 'x86',
                storageType: 'local',
                locations: array_map(fn (string $l): array => self::serverTypeAvailability($l), $eu),
                prices: array_map(fn (string $l): array => self::serverTypePrice($l, '0.0156', '8.58', 20 * self::TB), $eu),
                deprecated: true,
                deprecation: ['unavailable_after' => '2025-06-01T00:00:00+00:00', 'unavailable' => true],
            ),
            self::serverType(
                id: 5,
                name: 'cax31',
                description: 'CAX31',
                cores: 4,
                memoryGb: 16.0,
                diskGb: 160,
                cpuType: 'shared',
                architecture: 'arm',
                storageType: 'local',
                locations: array_map(fn (string $l): array => self::serverTypeAvailability($l), $eu),
                prices: array_map(fn (string $l): array => self::serverTypePrice($l, '0.0153', '8.39', 20 * self::TB), $eu),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serverTypesResponse(int $page = 1, int $perPage = 50): array
    {
        $all = self::serverTypes();
        $chunk = array_slice($all, ($page - 1) * $perPage, $perPage);

        return [
            'server_types' => $chunk,
            'meta' => ['pagination' => self::pagination($page, $perPage, count($all))],
        ];
    }

    // ------------------------------------------------------------------
    // Pricing
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function pricingResponse(): array
    {
        $types = [];

        foreach (self::serverTypes() as $type) {
            $types[] = [
                'id' => $type['id'],
                'name' => $type['name'],
                'prices' => $type['prices'],
            ];
        }

        return [
            'pricing' => [
                'currency' => 'EUR',
                'vat_rate' => '19.000000',
                'image' => ['price_per_gb_month' => ['net' => '0.0106', 'gross' => '0.012614']],
                'floating_ip' => ['price_monthly' => ['net' => '1.0', 'gross' => '1.19']],
                'primary_ip' => [
                    'prices' => [
                        ['type' => 'ipv4', 'price_monthly' => ['net' => '0.5', 'gross' => '0.595']],
                        ['type' => 'ipv6', 'price_monthly' => ['net' => '0.0', 'gross' => '0.0']],
                    ],
                ],
                'traffic' => ['price_per_tb' => ['net' => '1.0', 'gross' => '1.19']],
                'server_backup' => ['percentage' => '20.000000'],
                'server_types' => $types,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function image(int $id, string $name, string $description, string $osFlavor, string $osVersion, string $architecture, ?string $deprecated = null, string $status = 'available'): array
    {
        return [
            'id' => $id,
            'type' => 'system',
            'status' => $status,
            'name' => $name,
            'description' => $description,
            'image_size' => 2.3,
            'disk_size' => 10,
            'created' => '2024-01-01T00:00:00+00:00',
            'created_from' => null,
            'bound_to' => null,
            'os_flavor' => $osFlavor,
            'os_version' => $osVersion,
            'rapid_deploy' => true,
            'protection' => ['delete' => false],
            'deprecated' => $deprecated,
            'architecture' => $architecture,
            'labels' => [],
            'deleted' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function images(): array
    {
        return [
            self::image(1001, 'ubuntu-24.04', 'Ubuntu 24.04', 'ubuntu', '24.04', 'x86'),
            self::image(1002, 'ubuntu-24.04-arm64', 'Ubuntu 24.04 arm64', 'ubuntu', '24.04', 'arm'),
            self::image(1003, 'debian-12', 'Debian 12', 'debian', '12', 'x86'),
            self::image(1004, 'fedora-40', 'Fedora 40', 'fedora', '40', 'x86', deprecated: '2025-01-01T00:00:00+00:00'),
            self::image(1005, 'centos-stream-9', 'CentOS Stream 9', 'centos', 'stream-9', 'x86'),
            self::image(1006, 'windows-server-2022', 'Windows Server 2022', 'windows', 'server-2022', 'x86', status: 'deprecated'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function imagesResponse(int $page = 1, int $perPage = 50): array
    {
        $all = self::images();
        $chunk = array_slice($all, ($page - 1) * $perPage, $perPage);

        return [
            'images' => $chunk,
            'meta' => ['pagination' => self::pagination($page, $perPage, count($all))],
        ];
    }

    // ------------------------------------------------------------------
    // Servers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function server(int $id = 1234, string $name = 'my-server', string $status = 'running'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'status' => $status,
            'created' => '2024-01-01T00:00:00+00:00',
            'public_net' => [
                'ipv4' => [
                    'id' => 111,
                    'ip' => '1.2.3.4',
                    'blocked' => false,
                    'dns_ptr' => 'static.1.2.3.4.clients.your-server.de',
                ],
                'ipv6' => [
                    'id' => 222,
                    'ip' => '2a01:4f8:1c1c:1::/64',
                    'blocked' => false,
                    'dns_ptr' => [],
                ],
                'floating_ips' => [],
                'primary_ips' => [],
            ],
            'server_type' => ['id' => 1, 'name' => 'cx22'],
            'datacenter' => [
                'id' => 4,
                'name' => 'fsn1-dc14',
                'description' => 'Falkenstein 1 DC 14',
                'location' => self::location('fsn1', 'Falkenstein DC Park 1', 'DE', 'Falkenstein', 'eu-central', 50.47612, 12.370071),
            ],
            'image' => self::image(1001, 'ubuntu-24.04', 'Ubuntu 24.04', 'ubuntu', '24.04', 'x86'),
            'iso' => null,
            'rescue_enabled' => false,
            'locked' => false,
            'backup_window' => null,
            'outgoing_traffic' => 0,
            'ingoing_traffic' => 0,
            'included_traffic' => 20 * self::TB,
            'protection' => ['delete' => false, 'rebuild' => false],
            'labels' => ['app' => 'vps-platform', 'provisioning-uuid' => 'test-uuid-1234'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function createdServerResponse(): array
    {
        return [
            'action' => self::action('create_server', 'success'),
            'next_actions' => [self::action('start_server', 'running')],
            'root_password' => 'h3tz-ROOT-p4ssw0rd!',
            'server' => self::server(status: 'initializing'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serverResponse(int $id = 1234, string $status = 'running'): array
    {
        return ['server' => self::server($id, status: $status)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serversListResponse(?string $labelSelector = null): array
    {
        $servers = [self::server()];
        if ($labelSelector !== null) {
            $servers = array_values(array_filter(
                $servers,
                fn (array $s): bool => in_array($labelSelector, $s['labels'] ?? [], true)
                    || str_starts_with($labelSelector, 'provisioning-uuid=') && isset($s['labels']['provisioning-uuid'])
            ));
        }

        return ['servers' => $servers, 'meta' => ['pagination' => self::pagination(1, 50, count($servers))]];
    }

    // ------------------------------------------------------------------
    // Actions & metrics
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function action(string $command, string $status = 'success', ?array $error = null): array
    {
        return [
            'id' => 123,
            'command' => $command,
            'status' => $status,
            'progress' => $status === 'success' ? 100 : 0,
            'started' => '2024-01-01T00:00:00+00:00',
            'finished' => $status === 'success' ? '2024-01-01T00:00:05+00:00' : null,
            'resources' => [
                ['id' => 1234, 'type' => 'server'],
            ],
            'error' => $error,
            'server' => ['id' => 1234, 'name' => 'my-server'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function actionResponse(string $command): array
    {
        return ['action' => self::action($command)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function resetPasswordResponse(): array
    {
        return [
            'action' => self::action('reset_password'),
            'root_password' => 'new-ROOT-p4ssw0rd!',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metricsResponse(): array
    {
        return [
            'metrics' => [
                'start' => '2024-01-01T00:00:00Z',
                'end' => '2024-01-01T00:10:00Z',
                'step' => 60,
                'time_series' => [
                    'cpu' => [
                        'values' => [
                            ['2024-01-01T00:00:00Z', '0.5'],
                            ['2024-01-01T00:05:00Z', '12.75'],
                        ],
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Errors
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function error(int $status, string $code, string $message, array $details = []): array
    {
        return ['error' => ['code' => $code, 'message' => $message, 'details' => $details]];
    }

    /**
     * @return array<int, array{0: int, 1: array<string, mixed>}>
     */
    public static function errorFixtures(): array
    {
        return [
            [401, self::error(401, 'unauthorized', 'unable to authorize you')],
            [403, self::error(403, 'forbidden', 'insufficient permissions')],
            [404, self::error(404, 'not_found', 'resource not found')],
            [409, self::error(409, 'conflict', 'action already in progress')],
            [422, self::error(422, 'invalid_input', 'validation failed', ['fields' => ['name' => ['must be unique']]])],
            [429, self::error(429, 'rate_limit_exceeded', 'rate limit exceeded')],
            [500, self::error(500, 'internal_error', 'something went wrong')],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function pagination(int $page, int $perPage, int $total): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));
        $nextPage = $page < $lastPage ? $page + 1 : null;

        return [
            'page' => $page,
            'per_page' => $perPage,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $nextPage,
            'last_page' => $lastPage,
            'total_entries' => $total,
        ];
    }
}
