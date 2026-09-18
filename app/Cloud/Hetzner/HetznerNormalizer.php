<?php

declare(strict_types=1);

namespace App\Cloud\Hetzner;

use App\Cloud\Data\ProviderActionData;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPrice;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Data\SafeMetadata;
use App\Cloud\Enums\ProviderActionStatus;
use App\Cloud\Enums\ProviderPowerState;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Exceptions\ProviderException;
use DateTimeImmutable;

/**
 * Hetzner JSON in, domain DTOs out.
 *
 * All the provider-shaped knowledge lives here so the provider class reads as a
 * sequence of operations and the reconcilers never learn a Hetzner field name.
 *
 * Two rules run through everything below.
 *
 * Nothing unrecognised becomes something reassuring. An unknown server status
 * is a normalized failure rather than `Active`: claiming a machine is healthy
 * because we did not recognise the word for what is wrong with it is how a
 * customer is billed for something broken.
 *
 * Metadata is picked, never copied. Only named scalar fields survive, so a new
 * field Hetzner adds one day cannot arrive in `provider_metadata` — which is
 * read, logged and shown — without somebody adding it here on purpose.
 */
final readonly class HetznerNormalizer
{
    /** The label that ties a remote machine to one local order. */
    public const TOKEN_LABEL = 'provisioning-uuid';

    /** Marks the machines this application created. */
    public const APP_LABEL = 'app';

    public const APP_VALUE = 'vps-platform';

    public function __construct(private HetznerErrorMapper $errors) {}

    /**
     * One Hetzner server.
     *
     * @param  array<string, mixed>  $server
     *
     * @throws ProviderException
     */
    public function server(array $server): ProviderServerData
    {
        $id = self::requireScalarId($server, 'id', 'server');

        $status = $this->serverStatus(self::stringOf($server, 'status'));
        $public = is_array($server['public_net'] ?? null) ? $server['public_net'] : [];
        $type = is_array($server['server_type'] ?? null) ? $server['server_type'] : [];
        $image = is_array($server['image'] ?? null) ? $server['image'] : [];
        $datacenter = is_array($server['datacenter'] ?? null) ? $server['datacenter'] : [];
        $location = is_array($datacenter['location'] ?? null) ? $datacenter['location'] : [];
        $labels = is_array($server['labels'] ?? null) ? $server['labels'] : [];

        return new ProviderServerData(
            providerServerId: $id,
            // From the label, never from the name: a name is cosmetic and an
            // operator may rename a machine, while the label is the identity
            // the create committed to.
            provisioningToken: self::labelValue($labels, self::TOKEN_LABEL),
            name: self::stringOf($server, 'name') ?? $id,
            providerPlanId: self::stringOf($type, 'name') ?? self::scalarId($type, 'id') ?? '',
            // The location, taken through the datacenter it belongs to. The
            // flat datacenter fields that used to sit on a server are gone.
            providerLocationId: self::stringOf($location, 'name') ?? self::scalarId($location, 'id') ?? '',
            providerImageId: self::stringOf($image, 'name') ?? self::scalarId($image, 'id') ?? '',
            status: $status,
            powerState: $this->powerState($server, $status),
            ipv4: self::ipv4($public),
            ipv6: self::ipv6($public),
            metadata: SafeMetadata::pick([
                'architecture' => self::stringOf($type, 'architecture'),
                'server_type' => self::stringOf($type, 'name'),
                'location' => self::stringOf($location, 'name'),
                'network_zone' => self::stringOf($location, 'network_zone'),
                'country' => self::stringOf($location, 'country'),
                'created' => self::stringOf($server, 'created'),
                'included_traffic' => is_int($server['included_traffic'] ?? null) ? $server['included_traffic'] : null,
                'image_os_flavor' => self::stringOf($image, 'os_flavor'),
                'image_os_version' => self::stringOf($image, 'os_version'),
                'public_net' => self::ipv4($public) !== null || self::ipv6($public) !== null,
            ], [
                'architecture', 'server_type', 'location', 'network_zone', 'country',
                'created', 'included_traffic', 'image_os_flavor', 'image_os_version', 'public_net',
            ]),
        );
    }

    /**
     * One Hetzner action.
     *
     * @param  array<string, mixed>  $action
     *
     * @throws ProviderException
     */
    public function action(array $action, string $command, ?string $providerServerId = null): ProviderActionData
    {
        $id = self::requireScalarId($action, 'id', 'action');
        $status = $this->actionStatus(self::stringOf($action, 'status'));

        $error = is_array($action['error'] ?? null) ? $action['error'] : [];
        $errorCode = self::stringOf($error, 'code');

        return new ProviderActionData(
            providerActionId: $id,
            command: $command,
            status: $status,
            providerServerId: $providerServerId ?? self::actionResourceId($action),
            startedAt: self::time(self::stringOf($action, 'started')) ?? new DateTimeImmutable,
            finishedAt: self::time(self::stringOf($action, 'finished')),
            metadata: SafeMetadata::pick([
                'command' => self::stringOf($action, 'command') ?? $command,
                'progress' => is_int($action['progress'] ?? null) ? $action['progress'] : null,
                'error_code' => $errorCode,
            ], ['command', 'progress', 'error_code']),
            // Only for a failure, and only when the code is one we recognise.
            // A null here means "nobody has established what this means", and
            // the server-action layer parks it rather than guessing.
            errorCategory: $status === ProviderActionStatus::Error
                ? $this->errors->actionCategory($errorCode)
                : null,
        );
    }

    /**
     * One Hetzner location.
     *
     * @param  array<string, mixed>  $location
     */
    public function location(array $location): ?ProviderLocationData
    {
        $name = self::stringOf($location, 'name');

        if ($name === null) {
            return null;
        }

        return new ProviderLocationData(
            // The name, because that is what the create endpoint accepts.
            providerLocationId: $name,
            name: self::stringOf($location, 'description') ?? $name,
            countryCode: strtoupper(self::stringOf($location, 'country') ?? ''),
            city: self::stringOf($location, 'city') ?? '',
            available: true,
            metadata: SafeMetadata::pick([
                'network_zone' => self::stringOf($location, 'network_zone'),
                'latitude' => self::floatOf($location, 'latitude'),
                'longitude' => self::floatOf($location, 'longitude'),
            ], ['network_zone', 'latitude', 'longitude']),
        );
    }

    /**
     * One Hetzner server type, priced for the locations that still serve it.
     *
     * Server types are location-aware, so "does this plan exist" and "can this
     * plan be bought here" are different questions. The per-location prices
     * carry their own deprecation, and that is what decides availability — the
     * type-level flag alone would keep selling a plan a location has retired.
     *
     * @param  array<string, mixed>  $type
     *
     * @throws ProviderException
     */
    public function plan(array $type): ?ProviderPlanData
    {
        $name = self::stringOf($type, 'name');

        if ($name === null) {
            return null;
        }

        $prices = self::locationPrices($type);
        $cheapest = null;

        foreach ($prices as $price) {
            if (! $this->locationPriceIsOrderable($price)) {
                continue;
            }

            $monthly = self::priceOf($price, 'price_monthly');

            if ($monthly instanceof ProviderPrice
                && ($cheapest === null || bccomp($monthly->amount, $cheapest->amount, 10) < 0)) {
                $cheapest = $monthly;
            }
        }

        if (! $cheapest instanceof ProviderPrice) {
            // Nowhere still sells it. A plan with no orderable location is not
            // a catalogue entry, it is a retired one.
            return null;
        }

        $hourly = null;

        foreach ($prices as $price) {
            if ($this->locationPriceIsOrderable($price)) {
                $hourly = self::priceOf($price, 'price_hourly');

                break;
            }
        }

        return new ProviderPlanData(
            providerPlanId: $name,
            name: self::stringOf($type, 'description') ?? $name,
            vcpu: (int) (self::floatOf($type, 'cores') ?? 0),
            ramMb: (int) round((self::floatOf($type, 'memory') ?? 0.0) * 1024),
            diskGb: (int) (self::floatOf($type, 'disk') ?? 0),
            bandwidthGb: null,
            monthlyPrice: $cheapest,
            hourlyPrice: $hourly,
            metadata: SafeMetadata::pick([
                'architecture' => self::stringOf($type, 'architecture'),
                'cpu_type' => self::stringOf($type, 'cpu_type'),
                'category' => self::stringOf($type, 'category'),
                'cores' => self::floatOf($type, 'cores'),
                'memory_gb' => self::floatOf($type, 'memory'),
            ], ['architecture', 'cpu_type', 'category', 'cores', 'memory_gb']),
        );
    }

    /**
     * One Hetzner image, if it is a system image a customer may buy.
     *
     * Snapshots and backups are somebody's own data, not an OS on the menu.
     *
     * @param  array<string, mixed>  $image
     */
    public function image(array $image): ?ProviderImageData
    {
        if (self::stringOf($image, 'type') !== 'system') {
            return null;
        }

        $name = self::stringOf($image, 'name') ?? self::scalarId($image, 'id');

        if ($name === null) {
            return null;
        }

        $status = self::stringOf($image, 'status');

        if ($status !== null && $status !== 'available') {
            // Still creating, or unusable. Not something to offer for sale.
            return null;
        }

        return new ProviderImageData(
            providerImageId: $name,
            name: self::stringOf($image, 'description') ?? $name,
            osFamily: self::stringOf($image, 'os_flavor') ?? 'unknown',
            version: self::stringOf($image, 'os_version') ?? '',
            architecture: self::stringOf($image, 'architecture') ?? 'x86',
            // Hetzner announces a removal date before it stops working. An
            // image inside that window still runs, but must not be presented
            // as a healthy choice for a new sale.
            deprecated: self::stringOf($image, 'deprecated') !== null,
            metadata: SafeMetadata::pick([
                'os_flavor' => self::stringOf($image, 'os_flavor'),
                'os_version' => self::stringOf($image, 'os_version'),
                'architecture' => self::stringOf($image, 'architecture'),
                'rapid_deploy' => is_bool($image['rapid_deploy'] ?? null) ? $image['rapid_deploy'] : null,
            ], ['os_flavor', 'os_version', 'architecture', 'rapid_deploy']),
        );
    }

    /**
     * Whether this per-location price is still orderable.
     *
     * The current API carries deprecation on the location entry rather than
     * only on the type, so this is where "can I still buy it here" is decided.
     *
     * @param  array<string, mixed>  $price
     */
    public function locationPriceIsOrderable(array $price): bool
    {
        $deprecation = $price['deprecation'] ?? null;

        if (! is_array($deprecation)) {
            return true;
        }

        $unavailableAfter = self::time(self::stringOf($deprecation, 'unavailable_after'));

        // Announced but not yet withdrawn is still orderable; past the date it
        // is not, whatever the global list says.
        return ! ($unavailableAfter instanceof DateTimeImmutable && $unavailableAfter <= new DateTimeImmutable);
    }

    /**
     * The per-location price rows of one server type.
     *
     * @param  array<string, mixed>  $type
     * @return list<array<string, mixed>>
     */
    public static function locationPrices(array $type): array
    {
        $prices = $type['prices'] ?? null;

        if (! is_array($prices)) {
            return [];
        }

        $rows = [];

        foreach ($prices as $price) {
            if (is_array($price)) {
                $rows[] = $price;
            }
        }

        return $rows;
    }

    /**
     * A Hetzner price block as an exact decimal.
     *
     * Gross, deliberately. It is the figure actually charged to us, and a
     * provider cost that quietly excludes tax understates what a sale has to
     * cover. Isolated here so the convention can change without touching a
     * single order's history.
     *
     * @param  array<string, mixed>  $price
     */
    public static function priceOf(array $price, string $key): ?ProviderPrice
    {
        $block = $price[$key] ?? null;

        if (! is_array($block)) {
            return null;
        }

        $amount = $block['gross'] ?? $block['net'] ?? null;

        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }

        $decimal = is_string($amount) ? trim($amount) : (string) $amount;

        if (! preg_match('/^-?\d+(\.\d+)?$/', $decimal)) {
            return null;
        }

        return ProviderPrice::of($decimal, self::currency($price));
    }

    /** @param array<string, mixed> $price */
    public static function currency(array $price): string
    {
        $currency = $price['currency'] ?? null;

        return is_string($currency) && preg_match('/^[A-Za-z]{3}$/', $currency)
            ? strtoupper($currency)
            : 'EUR';
    }

    /**
     * Hetzner's server status, or a normalized failure.
     *
     * @throws ProviderException
     */
    public function serverStatus(?string $status): ProviderServerStatus
    {
        return match ($status) {
            'initializing', 'starting', 'migrating', 'rebuilding' => ProviderServerStatus::Provisioning,
            'running', 'off', 'stopping' => ProviderServerStatus::Active,
            'deleting' => ProviderServerStatus::Deleting,
            'unknown' => ProviderServerStatus::Error,
            default => throw $this->errors->malformed(
                'server',
                // The value itself is a provider enum, not customer data.
                'an unrecognised server status was returned: '.($status ?? 'none'),
            ),
        };
    }

    /**
     * @throws ProviderException
     */
    public function actionStatus(?string $status): ProviderActionStatus
    {
        return match ($status) {
            'running' => ProviderActionStatus::Running,
            'success' => ProviderActionStatus::Success,
            'error' => ProviderActionStatus::Error,
            default => throw $this->errors->malformed(
                'action',
                'an unrecognised action status was returned: '.($status ?? 'none'),
            ),
        };
    }

    /** @param array<string, mixed> $server */
    private function powerState(array $server, ProviderServerStatus $status): ProviderPowerState
    {
        return match (self::stringOf($server, 'status')) {
            'running' => ProviderPowerState::On,
            'off' => ProviderPowerState::Off,
            // Mid-transition, or a machine in an error state. Saying "on"
            // because it is not explicitly off would be a guess the customer's
            // screen then repeats as fact.
            default => ProviderPowerState::Unknown,
        };
    }

    /**
     * @param  array<string, mixed>  $labels
     */
    public static function labelValue(array $labels, string $key): ?string
    {
        $value = $labels[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $action */
    private static function actionResourceId(array $action): ?string
    {
        $resources = $action['resources'] ?? null;

        if (! is_array($resources)) {
            return null;
        }

        foreach ($resources as $resource) {
            if (is_array($resource) && ($resource['type'] ?? null) === 'server') {
                return self::scalarId($resource, 'id');
            }
        }

        return null;
    }

    /** @param array<string, mixed> $public */
    private static function ipv4(array $public): ?string
    {
        $block = $public['ipv4'] ?? null;
        $ip = is_array($block) ? ($block['ip'] ?? null) : null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /** @param array<string, mixed> $public */
    private static function ipv6(array $public): ?string
    {
        $block = $public['ipv6'] ?? null;
        $ip = is_array($block) ? ($block['ip'] ?? null) : null;

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * @param  array<string, mixed>  $source
     *
     * @throws ProviderException
     */
    private function requireScalarId(array $source, string $key, string $resource): string
    {
        $id = self::scalarId($source, $key);

        if ($id === null) {
            throw $this->errors->malformed($resource, 'the '.$resource.' carried no identifier.');
        }

        return $id;
    }

    /** @param array<string, mixed> $source */
    private static function scalarId(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $source */
    private static function stringOf(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $source */
    private static function floatOf(array $source, string $key): ?float
    {
        $value = $source[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function time(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
