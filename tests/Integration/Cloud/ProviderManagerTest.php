<?php

declare(strict_types=1);

use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Enums\ProviderCapability;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\Fake\FakeProvider;
use App\Cloud\ProviderManager;
use App\Models\Provider;
use Illuminate\Support\Facades\Schema;

it('resolves a registered code to its implementation', function (): void {
    expect(app(ProviderManager::class)->driver('fake'))->toBeInstanceOf(FakeProvider::class);
});

it('refuses a code that is not registered', function (): void {
    try {
        app(ProviderManager::class)->driver('hetzner');
        expect(false)->toBeTrue('expected a ProviderException');
    } catch (ProviderException $exception) {
        expect($exception->category)->toBe(ProviderErrorCategory::InvalidRequest);
    }
});

it('cannot be made to instantiate a class named in the database', function (): void {
    // The attack this design exists to prevent: writing a class name into the
    // providers table and having the application build it. The code is only
    // ever a key into the static registry, so a class name is simply an
    // unregistered code.
    $provider = Provider::query()->create([
        'code' => 'App\\Cloud\\Fake\\FakeProvider',
        'name' => 'Injected',
    ]);

    expect(fn () => app(ProviderManager::class)->for($provider))
        ->toThrow(ProviderException::class);
});

it('refuses a dangerous class name written as a provider code', function (string $code): void {
    $provider = Provider::query()->create(['code' => $code, 'name' => 'Injected']);

    expect(fn () => app(ProviderManager::class)->for($provider))
        ->toThrow(ProviderException::class);
})->with([
    'system command runner' => ['Symfony\\Component\\Process\\Process'],
    'file writer' => ['SplFileObject'],
    'arbitrary class' => ['stdClass'],
]);

it('refuses a registry entry that is not a provider', function (): void {
    // A configuration mistake must fail loudly rather than build the object
    // anyway and discover the problem when it is asked to spend money.
    config()->set('providers.implementations.broken', stdClass::class);

    expect(fn () => app(ProviderManager::class)->driver('broken'))
        ->toThrow(ProviderException::class);
});

it('stores no class name column on the providers table', function (): void {
    foreach (['class', 'class_name', 'implementation', 'driver_class', 'handler'] as $column) {
        expect(Schema::hasColumn('providers', $column))->toBeFalse("providers.{$column} must not exist");
    }
});

it('refuses a disabled provider', function (): void {
    // Honouring the operator's kill switch is the entire point of it.
    $provider = Provider::query()->create(['code' => 'fake', 'name' => 'Fake', 'enabled' => false]);

    try {
        app(ProviderManager::class)->for($provider);
        expect(false)->toBeTrue('expected a ProviderException');
    } catch (ProviderException $exception) {
        expect($exception->category)->toBe(ProviderErrorCategory::Unavailable);
    }
});

it('resolves an enabled provider row', function (): void {
    $provider = Provider::query()->create(['code' => 'fake', 'name' => 'Fake', 'enabled' => true]);

    expect(app(ProviderManager::class)->for($provider))->toBeInstanceOf(CloudProviderInterface::class);
});

it('only ever returns something implementing the contract', function (): void {
    $manager = app(ProviderManager::class);

    foreach ($manager->registeredCodes() as $code) {
        expect($manager->driver($code))->toBeInstanceOf(CloudProviderInterface::class);
    }
});

it('registers no provider without an implementation', function (): void {
    // A registry entry for a provider that cannot provision would be a promise
    // the system cannot keep. Hetzner arrives with its adapter.
    expect(app(ProviderManager::class)->registeredCodes())->toBe(['fake'])
        ->and(app(ProviderManager::class)->isRegistered('hetzner'))->toBeFalse();
});

it('derives capabilities from the implemented interfaces', function (): void {
    $manager = app(ProviderManager::class);
    $provider = $manager->driver('fake');

    foreach ($manager->capabilitiesFor($provider) as $capability) {
        expect($provider)->toBeInstanceOf($capability->interface());
    }

    // And nothing is advertised that the class does not implement.
    foreach (ProviderCapability::cases() as $capability) {
        expect($capability->isOfferedBy($provider))->toBe($provider instanceof ($capability->interface()));
    }
});
