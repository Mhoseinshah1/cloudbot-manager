<?php

use App\Contracts\Data\ProviderImageData;
use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPlanData;
use App\Contracts\Data\ProviderServerData;
use App\Contracts\Data\ProviderUsageData;
use App\Exceptions\ProviderAuthenticationException;
use App\Exceptions\ProviderValidationException;
use App\Providers\Cloud\HetznerProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\HetznerApiFixtures as F;

function hetznerProvider(array $options = []): HetznerProvider
{
    return new HetznerProvider(
        credentials: ['token' => F::TOKEN],
        options: [
            'base_url' => F::BASE_URL,
            'retry_attempts' => 1,
            'retry_delay_ms' => 1,
            ...$options,
        ],
    );
}

it('exposes the provider code, name and accurate capabilities', function () {
    $provider = hetznerProvider();

    expect($provider->code())->toBe('hetzner');
    expect($provider->name())->toBe('Hetzner Cloud');

    $capabilities = $provider->capabilities();
    expect($capabilities['supportsPowerOn'])->toBeTrue();
    expect($capabilities['supportsPowerOff'])->toBeTrue();
    expect($capabilities['supportsReboot'])->toBeTrue();
    expect($capabilities['supportsRebuild'])->toBeTrue();
    expect($capabilities['supportsResetPassword'])->toBeTrue();
    expect($capabilities['supportsSnapshots'])->toBeTrue();
    expect($capabilities['supportsUsage'])->toBeTrue();
    // No generic suspend: Hetzner has no provider-neutral suspend lifecycle action.
    expect($capabilities['supportsSuspend'])->toBeFalse();
});

it('normalizes locations into shared DTOs', function () {
    Http::fake(['api.hetzner.test/v1/locations*' => Http::response(F::locationsResponse())]);

    $locations = hetznerProvider()->getLocations();

    expect($locations)->toHaveCount(5);
    expect($locations[0])->toBeInstanceOf(ProviderLocationData::class);
    expect($locations[0]->id)->toBe('fsn1');
    expect($locations[0]->countryCode)->toBe('DE');
    expect($locations[0]->city)->toBe('Falkenstein');
    expect($locations[0]->metadata['network_zone'])->toBe('eu-central');
});

it('normalizes server types and preserves per-location availability', function () {
    Http::fake(['api.hetzner.test/v1/server_types*' => Http::response(F::serverTypesResponse())]);

    $plans = hetznerProvider()->getPlans();

    expect($plans)->toHaveCount(5);
    expect($plans[0])->toBeInstanceOf(ProviderPlanData::class);

    $cx22 = collect($plans)->first(fn (ProviderPlanData $p): bool => $p->id === 'cx22');

    expect($cx22->vcpu)->toBe(2);
    expect($cx22->ramMb)->toBe(4096);
    expect($cx22->diskGb)->toBe(40);
    expect($cx22->bandwidthGb)->toBe(20480); // 20 TiB
    expect($cx22->priceMonthly)->toBe(4.29);
    expect($cx22->metadata['cpu_type'])->toBe('shared');
    expect($cx22->metadata['architecture'])->toBe('x86');

    // Per-location availability (post Sept 2025 schema) must be preserved.
    $availableAt = array_map(fn (array $e): string => $e['location'], $cx22->metadata['locations']);
    expect($availableAt)->toContain('fsn1', 'ash');
    $hil = collect($cx22->metadata['locations'])->first(fn (array $e): bool => $e['location'] === 'hil');
    expect($hil['deprecation'])->not->toBeNull();

    // Globally deprecated types must be flagged.
    $cx32 = collect($plans)->first(fn (ProviderPlanData $p): bool => $p->id === 'cx32');
    expect($cx32->metadata['deprecated'])->toBeTrue();
    expect($cx32->metadata['deprecation'])->not->toBeNull();
});

it('normalizes pricing from GET /pricing without hardcoding values', function () {
    Http::fake(['api.hetzner.test/v1/pricing' => Http::response(F::pricingResponse())]);

    $rows = hetznerProvider()->getPricing();

    expect($rows)->not->toBeEmpty();
    $cx22Fsn = collect($rows)->first(
        fn ($row): bool => $row->serverTypeId === 'cx22' && $row->locationId === 'fsn1'
    );
    expect($cx22Fsn->priceMonthly)->toBe(4.29);
    expect($cx22Fsn->currency)->toBe('EUR');
    expect($cx22Fsn->includedTraffic)->toBe(20 * F::TB);
    expect($cx22Fsn->pricePerTbTraffic)->toBe(1.0);
});

it('normalizes system images and carries deprecation state', function () {
    Http::fake([
        'api.hetzner.test/v1/images*' => fn (Request $request) => Http::response(F::imagesResponse()),
    ]);

    $images = hetznerProvider()->getImages();

    expect($images)->toHaveCount(6);
    expect($images[0])->toBeInstanceOf(ProviderImageData::class);
    expect($images[0]->id)->toBe('1001');
    expect($images[0]->osDistro)->toBe('ubuntu');
    expect($images[0]->version)->toBe('24.04');
    expect($images[0]->architecture)->toBe('x86');

    $fedora = collect($images)->first(fn (ProviderImageData $i): bool => $i->id === '1004');
    expect($fedora->metadata['deprecated'])->not->toBeNull();
});

it('creates a server with the normalized input and captures the root password once', function () {
    Http::fake([
        'api.hetzner.test/v1/servers*' => function (Request $request) {
            // POST /servers creates; the adapter then confirms via GET /servers/{id}.
            if ($request->method() === 'POST') {
                return Http::response(F::createdServerResponse());
            }

            return Http::response(F::serverResponse(status: 'initializing'));
        },
    ]);

    $provider = hetznerProvider();
    $server = $provider->createServer(
        new ProviderPlanData(id: 'cx22', name: 'CX22', vcpu: 2, ramMb: 4096, diskGb: 40),
        new ProviderImageData(id: '1001', name: 'Ubuntu 24.04'),
        new ProviderLocationData(id: 'fsn1', name: 'Falkenstein DC Park 1'),
        'my-server',
        options: ['labels' => ['app' => 'vps-platform', 'provisioning-uuid' => 'uuid-1']],
    );

    expect($server)->toBeInstanceOf(ProviderServerData::class);
    expect($server->id)->toBe('1234');
    expect($server->ipAddress)->toBe('1.2.3.4');
    expect($server->rootPassword)->toBe('h3tz-ROOT-p4ssw0rd!');
    expect($server->status)->toBe('initializing');
    expect($server->planId)->toBe('cx22');
    expect($server->imageId)->toBe('1001');
    expect($server->locationId)->toBe('fsn1');
    expect($server->metadata['ipv6'])->toBe('2a01:4f8:1c1c:1::/64');

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/servers')) {
            return false;
        }
        $body = $request->data();

        return ($body['server_type'] ?? null) === 'cx22'
            && ($body['image'] ?? null) === 1001
            && ($body['location'] ?? null) === 'fsn1'
            && ($body['labels']['provisioning-uuid'] ?? null) === 'uuid-1';
    });
});

it('surfaces a server creation failure as a normalized validation exception', function () {
    Http::fake(['api.hetzner.test/v1/servers' => Http::response(F::error(422, 'invalid_input', 'validation failed', ['fields' => ['name' => ['invalid']]]), 422)]);

    expect(fn () => hetznerProvider()->createServer(
        new ProviderPlanData(id: 'cx22', name: 'CX22', vcpu: 2, ramMb: 4096, diskGb: 40),
        new ProviderImageData(id: '1001', name: 'Ubuntu 24.04'),
        new ProviderLocationData(id: 'fsn1', name: 'Falkenstein DC Park 1'),
        'my-server',
    ))->toThrow(ProviderValidationException::class);
});

it('surfaces authentication failures on server creation', function () {
    Http::fake(['api.hetzner.test/v1/servers' => Http::response(F::error(401, 'unauthorized', 'unable to authorize you'), 401)]);

    expect(fn () => hetznerProvider()->createServer(
        new ProviderPlanData(id: 'cx22', name: 'CX22', vcpu: 2, ramMb: 4096, diskGb: 40),
        new ProviderImageData(id: '1001', name: 'Ubuntu 24.04'),
        new ProviderLocationData(id: 'fsn1', name: 'Falkenstein DC Park 1'),
        'my-server',
    ))->toThrow(ProviderAuthenticationException::class);
});

it('retrieves and normalizes a server', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234' => Http::response(F::serverResponse(status: 'off'))]);

    $server = hetznerProvider()->getServer('1234');

    expect($server)->toBeInstanceOf(ProviderServerData::class);
    expect($server->id)->toBe('1234');
    expect($server->status)->toBe('off');
    expect($server->ipAddress)->toBe('1.2.3.4');
});

it('finds a server by its idempotency label', function () {
    Http::fake(['api.hetzner.test/v1/servers*' => Http::response(F::serversListResponse())]);

    $found = hetznerProvider()->findServerByLabel('provisioning-uuid', 'test-uuid-1234');

    expect($found)->not->toBeNull();
    expect($found->id)->toBe('1234');

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'label_selector=')
            && (str_contains($request->url(), 'provisioning-uuid%3Dtest-uuid-1234')
                || str_contains($request->url(), 'provisioning-uuid=test-uuid-1234'));
    });
});

it('returns null when no server matches the label', function () {
    Http::fake(['api.hetzner.test/v1/servers*' => Http::response(['servers' => [], 'meta' => ['pagination' => ['page' => 1, 'per_page' => 50, 'previous_page' => null, 'next_page' => null, 'last_page' => 1, 'total_entries' => 0]]])]);

    expect(hetznerProvider()->findServerByLabel('provisioning-uuid', 'missing'))->toBeNull();
});

it('executes power/lifecycle actions through the official endpoints', function () {
    Http::fake([
        'api.hetzner.test/v1/servers/1234/actions/poweron' => Http::response(F::actionResponse('poweron')),
        'api.hetzner.test/v1/servers/1234/actions/poweroff' => Http::response(F::actionResponse('poweroff')),
        'api.hetzner.test/v1/servers/1234/actions/reboot' => Http::response(F::actionResponse('reboot')),
        'api.hetzner.test/v1/servers/1234/actions/rebuild' => Http::response(F::actionResponse('rebuild')),
    ]);

    $provider = hetznerProvider();
    $provider->powerOn('1234');
    $provider->powerOff('1234');
    $provider->reboot('1234');
    $provider->rebuild('1234', new ProviderImageData(id: '1003', name: 'Debian 12'));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/servers/1234/actions/poweron'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/servers/1234/actions/poweroff'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/servers/1234/actions/reboot'));

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/servers/1234/actions/rebuild')
            && ($request->data()['image'] ?? null) === 1003;
    });
});

it('resets the password and returns it exactly once', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234/actions/reset_password' => Http::response(F::resetPasswordResponse())]);

    $password = hetznerProvider()->resetPassword('1234');

    expect($password)->toBe('new-ROOT-p4ssw0rd!');
});

it('deletes a server', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234' => Http::response(null, 204)]);

    hetznerProvider()->deleteServer('1234');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/servers/1234'));
});

it('normalizes cpu usage from the metrics endpoint', function () {
    Http::fake(['api.hetzner.test/v1/servers/1234/metrics*' => Http::response(F::metricsResponse())]);

    $usage = hetznerProvider()->getUsage('1234');

    expect($usage)->toBeInstanceOf(ProviderUsageData::class);
    expect($usage->cpuPercent)->toBe(12.75);
    expect($usage->metadata['metric_types'])->toBe(['cpu']);
    expect($usage->bandwidthGb)->toBeNull();
});

it('never includes the API token in adapter exceptions', function () {
    Http::fake(['api.hetzner.test/v1/servers' => Http::response(F::error(500, 'internal_error', 'boom'), 500)]);

    try {
        hetznerProvider()->createServer(
            new ProviderPlanData(id: 'cx22', name: 'CX22', vcpu: 2, ramMb: 4096, diskGb: 40),
            new ProviderImageData(id: '1001', name: 'Ubuntu 24.04'),
            new ProviderLocationData(id: 'fsn1', name: 'Falkenstein DC Park 1'),
            'my-server',
        );
        $this->fail('Expected ProviderApiException');
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain(F::TOKEN);
    }
});
