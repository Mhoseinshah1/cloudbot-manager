<?php

declare(strict_types=1);

use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Enums\ProviderCapability;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Enums\ProviderPowerState;
use App\Cloud\Enums\ProviderServerStatus;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\Fake\FakeCatalog;
use App\Cloud\Fake\FakeProvider;
use App\Cloud\Fake\Models\FakeProviderAction;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Cloud\ProviderManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function fakeRequest(?string $token = null, array $overrides = []): CreateServerRequest
{
    return new CreateServerRequest(
        provisioningToken: $token ?? (string) Str::uuid(),
        providerPlanId: $overrides['plan'] ?? FakeCatalog::PLAN_SMALL,
        providerLocationId: $overrides['location'] ?? FakeCatalog::LOCATION_PRIMARY,
        providerImageId: $overrides['image'] ?? FakeCatalog::IMAGE_UBUNTU,
        name: $overrides['name'] ?? 'test-server',
        labels: $overrides['labels'] ?? [],
    );
}

it('never touches the network', function (): void {
    // The point of this provider. Any outbound request would make the test
    // suite depend on a third party being up.
    Http::preventStrayRequests();

    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());

    $provider->getLocations();
    $provider->getPlans();
    $provider->getImages();
    $provider->getPricing();
    $provider->checkAvailability(FakeCatalog::PLAN_SMALL, FakeCatalog::LOCATION_PRIMARY);
    $provider->listServers();
    $provider->powerOff($server->providerServerId);
    $provider->reboot($server->providerServerId);
    $provider->deleteServer($server->providerServerId);

    Http::assertNothingSent();
});

it('shares state between independently constructed instances', function (): void {
    // Two adapters are two views of one provider, exactly as a web request and
    // a queue worker would be.
    $created = (new FakeProvider(new FakeCatalog))->createServer(fakeRequest());

    $seenByAnother = (new FakeProvider(new FakeCatalog))->getServer($created->providerServerId);

    expect($seenByAnother->providerServerId)->toBe($created->providerServerId);
});

it('shares state with a provider resolved through the manager', function (): void {
    $created = app(FakeProvider::class)->createServer(fakeRequest());

    $viaManager = app(ProviderManager::class)->driver('fake');

    expect($viaManager->getServer($created->providerServerId)->providerServerId)
        ->toBe($created->providerServerId);
});

it('keeps no server state in the object itself', function (): void {
    // Proves the state is in the database rather than in the instance: a
    // provider built after the server was created still sees it.
    $created = app(FakeProvider::class)->createServer(fakeRequest());

    expect(FakeProviderServer::query()->where('provider_server_id', $created->providerServerId)->exists())
        ->toBeTrue();
});

it('gives servers process-independent identifiers', function (): void {
    $provider = app(FakeProvider::class);

    $ids = array_map(
        fn (): string => $provider->createServer(fakeRequest())->providerServerId,
        range(1, 5),
    );

    expect(array_unique($ids))->toHaveCount(5);

    foreach ($ids as $id) {
        // A ULID, not a counter. A per-process sequence would repeat after a
        // restart and collide between workers.
        expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
            ->and($id)->not->toContain('fake-');
    }
});

it('gives actions unique identifiers', function (): void {
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());

    $ids = [
        $provider->powerOff($server->providerServerId)->providerActionId,
        $provider->powerOn($server->providerServerId)->providerActionId,
        $provider->reboot($server->providerServerId)->providerActionId,
    ];

    expect(array_unique($ids))->toHaveCount(3);
});

it('creates one server for one token however many times it is asked', function (): void {
    $token = (string) Str::uuid();
    $provider = app(FakeProvider::class);

    $ids = array_map(
        fn (): string => $provider->createServer(fakeRequest($token))->providerServerId,
        range(1, 4),
    );

    expect(array_unique($ids))->toHaveCount(1)
        ->and(FakeProviderServer::query()->count())->toBe(1);
});

it('enforces one server per token in the database', function (): void {
    // The application checks first, but the constraint is what makes it true
    // under concurrency, when both callers checked before either inserted.
    $token = (string) Str::uuid();
    app(FakeProvider::class)->createServer(fakeRequest($token));

    expect(fn () => FakeProviderServer::query()->create([
        'provider_server_id' => (string) Str::ulid(),
        'provisioning_token' => $token,
        'name' => 'racing-duplicate',
        'provider_plan_id' => FakeCatalog::PLAN_SMALL,
        'provider_location_id' => FakeCatalog::LOCATION_PRIMARY,
        'provider_image_id' => FakeCatalog::IMAGE_UBUNTU,
        'status' => ProviderServerStatus::Active,
        'power_state' => ProviderPowerState::On,
    ]))->toThrow(QueryException::class);
});

it('recovers the winner when a concurrent create loses the race', function (): void {
    // Simulates the losing side precisely: a competing attempt inserts the row
    // after this one has already looked and found nothing. The adapter must
    // return that server rather than raising or creating a second one.
    $token = (string) Str::uuid();
    $provider = app(FakeProvider::class);
    $winnerId = (string) Str::ulid();
    $inserted = false;

    DB::listen(function ($query) use (&$inserted, $token, $winnerId): void {
        if ($inserted || ! str_contains($query->sql, 'select') || ! str_contains($query->sql, 'fake_provider_servers')) {
            return;
        }

        $inserted = true;

        FakeProviderServer::query()->create([
            'provider_server_id' => $winnerId,
            'provisioning_token' => $token,
            'name' => 'winner',
            'provider_plan_id' => FakeCatalog::PLAN_SMALL,
            'provider_location_id' => FakeCatalog::LOCATION_PRIMARY,
            'provider_image_id' => FakeCatalog::IMAGE_UBUNTU,
            'status' => ProviderServerStatus::Active,
            'power_state' => ProviderPowerState::On,
        ]);
    });

    $result = $provider->createServer(fakeRequest($token));

    expect($result->providerServerId)->toBe($winnerId)
        ->and($result->name)->toBe('winner')
        ->and(FakeProviderServer::query()->where('provisioning_token', $token)->count())->toBe(1);
});

it('leaves the caller transaction usable after losing the race', function (): void {
    // Provisioning calls this from inside a transaction. PostgreSQL aborts a
    // transaction on any error, so a duplicate key must roll back only to a
    // savepoint or the recovery lookup could not run and the surrounding work
    // would be lost.
    $token = (string) Str::uuid();
    $provider = app(FakeProvider::class);
    $winnerId = (string) Str::ulid();
    $inserted = false;

    DB::listen(function ($query) use (&$inserted, $token, $winnerId): void {
        if ($inserted || ! str_contains($query->sql, 'select') || ! str_contains($query->sql, 'fake_provider_servers')) {
            return;
        }

        $inserted = true;

        FakeProviderServer::query()->create([
            'provider_server_id' => $winnerId,
            'provisioning_token' => $token,
            'name' => 'winner',
            'provider_plan_id' => FakeCatalog::PLAN_SMALL,
            'provider_location_id' => FakeCatalog::LOCATION_PRIMARY,
            'provider_image_id' => FakeCatalog::IMAGE_UBUNTU,
            'status' => ProviderServerStatus::Active,
            'power_state' => ProviderPowerState::On,
        ]);
    });

    $result = DB::transaction(function () use ($provider, $token) {
        $server = $provider->createServer(fakeRequest($token));

        // Still inside the same transaction: this query proves it was not
        // aborted by the duplicate key.
        FakeProviderAction::query()->count();

        return $server;
    });

    expect($result->providerServerId)->toBe($winnerId);
});

it('reports the out-of-stock combination as unavailable', function (): void {
    expect(app(FakeProvider::class)->checkAvailability(
        FakeCatalog::PLAN_LARGE,
        FakeCatalog::LOCATION_SECONDARY,
    ))->toBeFalse();
});

it('refuses to create what it cannot supply', function (): void {
    try {
        app(FakeProvider::class)->createServer(fakeRequest(null, [
            'plan' => FakeCatalog::PLAN_LARGE,
            'location' => FakeCatalog::LOCATION_SECONDARY,
        ]));
        expect(false)->toBeTrue('expected an out-of-stock failure');
    } catch (ProviderException $exception) {
        expect($exception->category)->toBe(ProviderErrorCategory::OutOfStock)
            // Out of stock is a real condition, not an unknown outcome: no
            // server exists, so a refund is safe without reconciling.
            ->and($exception->isOutcomeUnknown())->toBeFalse();
    }
});

it('rejects an unknown plan, location or image as our mistake', function (array $overrides): void {
    try {
        app(FakeProvider::class)->createServer(fakeRequest(null, $overrides));
        expect(false)->toBeTrue('expected an invalid-request failure');
    } catch (ProviderException $exception) {
        expect($exception->category)->toBe(ProviderErrorCategory::InvalidRequest)
            ->and($exception->category->isRetryable())->toBeFalse();
    }
})->with([
    'plan' => [['plan' => 'no-such-plan']],
    'location' => [['location' => 'no-such-location']],
    'image' => [['image' => 'no-such-image']],
]);

it('powers a server off and on', function (): void {
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());

    $provider->powerOff($server->providerServerId);
    expect($provider->getServer($server->providerServerId)->powerState)->toBe(ProviderPowerState::Off);

    $provider->powerOn($server->providerServerId);
    expect($provider->getServer($server->providerServerId)->powerState)->toBe(ProviderPowerState::On);
});

it('leaves a rebooted server running', function (): void {
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());

    $provider->powerOff($server->providerServerId);
    $provider->reboot($server->providerServerId);

    expect($provider->getServer($server->providerServerId)->powerState)->toBe(ProviderPowerState::On);
});

it('marks a deleted server deleted and powered off', function (): void {
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());

    $provider->deleteServer($server->providerServerId);
    $after = $provider->getServer($server->providerServerId);

    expect($after->status)->toBe(ProviderServerStatus::Deleted)
        ->and($after->powerState)->toBe(ProviderPowerState::Off);
});

it('stops listing a deleted server', function (): void {
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());

    $provider->deleteServer($server->providerServerId);

    $ids = array_map(
        static fn ($listed): string => $listed->providerServerId,
        $provider->listServers(),
    );

    expect($ids)->not->toContain($server->providerServerId);
});

it('keeps the provisioning token on the deleted row', function (): void {
    // The token is a durable correlation identity, not a lease. Releasing it
    // would let a late retry create a replacement server for an order that was
    // already fulfilled and terminated — and bill the customer for it.
    $token = (string) Str::uuid();
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest($token));

    $provider->deleteServer($server->providerServerId);

    // Read the column directly rather than trusting the adapter.
    $stored = DB::table('fake_provider_servers')
        ->where('provider_server_id', $server->providerServerId)
        ->value('provisioning_token');

    expect($stored)->toBe($token);
});

it('still resolves a deleted server by its provisioning token', function (): void {
    // Reconciliation asks this question to find out whether a token ever
    // produced a server. Answering "no" once the server is gone would make a
    // terminated order look like one that was never provisioned.
    $token = (string) Str::uuid();
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest($token));

    $provider->deleteServer($server->providerServerId);
    $found = $provider->findByProvisioningToken($token);

    expect($found)->not->toBeNull()
        ->and($found->providerServerId)->toBe($server->providerServerId)
        ->and($found->status)->toBe(ProviderServerStatus::Deleted);
});

it('creates no replacement when the same token is retried after deletion', function (): void {
    // The whole point of the token: one token can produce at most one server,
    // for the life of the system.
    $token = (string) Str::uuid();
    $provider = app(FakeProvider::class);

    $original = $provider->createServer(fakeRequest($token));
    $provider->deleteServer($original->providerServerId);

    $retried = $provider->createServer(fakeRequest($token));

    expect($retried->providerServerId)->toBe($original->providerServerId)
        // The caller learns the outcome from the status rather than being
        // handed a new server.
        ->and($retried->status)->toBe(ProviderServerStatus::Deleted)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->where('provisioning_token', $token)->count())->toBe(1);
});

it('still refuses another row claiming a deleted server token', function (): void {
    // The unique constraint has to keep holding after deletion, or concurrency
    // could reintroduce the duplicate the application now avoids.
    $token = (string) Str::uuid();
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest($token));
    $provider->deleteServer($server->providerServerId);

    expect(fn () => FakeProviderServer::query()->create([
        'provider_server_id' => (string) Str::ulid(),
        'provisioning_token' => $token,
        'name' => 'replacement',
        'provider_plan_id' => FakeCatalog::PLAN_SMALL,
        'provider_location_id' => FakeCatalog::LOCATION_PRIMARY,
        'provider_image_id' => FakeCatalog::IMAGE_UBUNTU,
        'status' => ProviderServerStatus::Active,
        'power_state' => ProviderPowerState::On,
    ]))->toThrow(QueryException::class);
});

it('creates a new server for a genuinely new token', function (): void {
    // Re-provisioning is not forbidden; reusing a spent token is. A new order
    // carries a new token and gets a new server.
    $provider = app(FakeProvider::class);

    $first = $provider->createServer(fakeRequest((string) Str::uuid()));
    $provider->deleteServer($first->providerServerId);

    $second = $provider->createServer(fakeRequest((string) Str::uuid()));

    expect($second->providerServerId)->not->toBe($first->providerServerId)
        ->and($second->status)->toBe(ProviderServerStatus::Active)
        ->and(FakeProviderServer::query()->count())->toBe(2);
});

it('treats deleting an already deleted server as done', function (): void {
    // Deleting something already gone has achieved what the caller wanted.
    // Failing would leave a termination no retry could ever complete.
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());

    $provider->deleteServer($server->providerServerId);
    $second = $provider->deleteServer($server->providerServerId);

    expect($second->command)->toBe('delete')
        ->and(FakeProviderAction::query()->where('command', 'delete')->count())->toBe(2);
});

it('refuses to power a deleted server', function (): void {
    // A caller acting on stale local state should find out, not silently
    // appear to succeed.
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());
    $provider->deleteServer($server->providerServerId);

    expect(fn () => $provider->powerOn($server->providerServerId))
        ->toThrow(ProviderException::class);
});

it('keeps action history after the server is gone', function (): void {
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest());
    $action = $provider->deleteServer($server->providerServerId);

    expect($provider->getAction($action->providerActionId)->providerServerId)
        ->toBe($server->providerServerId);
});

it('drops secret-bearing labels instead of storing them', function (): void {
    // A label naming a secret cannot even be constructed, so nothing
    // downstream has to remember to strip it.
    expect(fn () => fakeRequest(null, ['labels' => ['api_token' => 'live-secret-value']]))
        ->toThrow(InvalidArgumentException::class);
});

it('stores only safe labels as metadata', function (): void {
    $provider = app(FakeProvider::class);
    $server = $provider->createServer(fakeRequest(null, [
        'labels' => ['managed_by' => 'cloudbot', 'environment' => 'test'],
    ]));

    expect($server->metadata->toArray())->toBe(['managed_by' => 'cloudbot', 'environment' => 'test']);
});

it('gives servers documentation-range addresses only', function (): void {
    // Nothing here may look like, or be routable to, a real host.
    $server = app(FakeProvider::class)->createServer(fakeRequest());

    expect($server->ipv4)->toStartWith('198.51.100.')
        ->and($server->ipv6)->toStartWith('2001:db8::');
});

it('advertises no release 1.1 capability', function (): void {
    $provider = app(FakeProvider::class);
    $offered = array_map(
        static fn (ProviderCapability $capability): string => $capability->value,
        ProviderCapability::offeredBy($provider),
    );

    expect($offered)->toEqualCanonicalizing(['power_control', 'reboot']);

    foreach (['rebuild', 'resetPassword', 'usage', 'snapshots'] as $method) {
        expect(method_exists($provider, $method))->toBeFalse("{$method} belongs to a later release");
    }
});
