<?php

use App\Exceptions\ProviderException;
use App\Providers\Cloud\FakeProvider;

it('exposes the provider code and a human-readable name', function () {
    $provider = new FakeProvider;

    expect($provider->code())->toBe('fake');
    expect($provider->name())->toBeString();
});

it('advertises supported capabilities and no suspend/snapshots', function () {
    $capabilities = (new FakeProvider)->capabilities();

    expect($capabilities['supportsPowerOn'])->toBeTrue();
    expect($capabilities['supportsPowerOff'])->toBeTrue();
    expect($capabilities['supportsReboot'])->toBeTrue();
    expect($capabilities['supportsRebuild'])->toBeTrue();
    expect($capabilities['supportsResetPassword'])->toBeTrue();
    expect($capabilities['supportsSnapshots'])->toBeFalse();
    expect($capabilities['supportsSuspend'])->toBeFalse();
    expect($capabilities['supportsUsage'])->toBeTrue();
});

it('returns a deterministic catalog of locations, plans and images', function () {
    $provider = new FakeProvider;

    expect($provider->getLocations())->toHaveCount(3);
    expect($provider->getPlans())->toHaveCount(3);
    expect($provider->getImages())->toHaveCount(3);

    expect($provider->getPlans()[0]->priceMonthly)->toBe(4.5);
    expect($provider->getPlans()[1]->ramMb)->toBe(4096);
    expect($provider->getLocations()[0]->countryCode)->toBe('DE');
});

it('creates a server with provider id, ip and a root password', function () {
    $provider = new FakeProvider;

    $server = $provider->createServer(
        $provider->getPlans()[1],
        $provider->getImages()[0],
        $provider->getLocations()[0],
        'test-server',
    );

    expect($server->id)->toStartWith('fake-');
    expect($server->ipAddress)->toStartWith('10.0.0.');
    expect($server->rootPassword)->not->toBeEmpty();
    expect($server->status)->toBe('running');
    expect($server->planId)->toBe('cpx21');
});

it('supports lifecycle actions on created servers', function () {
    $provider = new FakeProvider;

    $server = $provider->createServer(
        $provider->getPlans()[0],
        $provider->getImages()[0],
        $provider->getLocations()[0],
        'srv',
    );

    $provider->powerOn($server->id);
    $provider->powerOff($server->id);
    $provider->reboot($server->id);
    $provider->rebuild($server->id, $provider->getImages()[1]);

    expect($provider->resetPassword($server->id))->not->toBeEmpty();

    $usage = $provider->getUsage($server->id);
    expect($usage->cpuPercent)->toBeFloat();

    $provider->deleteServer($server->id);

    expect(fn () => $provider->getServer($server->id))->toThrow(ProviderException::class);
});

it('injects provisioning failures when configured', function () {
    $provider = new FakeProvider(options: ['fail_create' => true]);

    expect(fn () => $provider->createServer(
        $provider->getPlans()[0],
        $provider->getImages()[0],
        $provider->getLocations()[0],
        'x',
    ))->toThrow(ProviderException::class);
});
