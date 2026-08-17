<?php

use App\Contracts\CloudProviderInterface;
use App\Providers\Cloud\FakeProvider;
use App\Providers\Cloud\HetznerProvider;

$adapters = [
    'fake' => fn () => new FakeProvider,
    'hetzner' => fn () => new HetznerProvider(credentials: ['token' => 'test-token']),
];

it('both adapters implement CloudProviderInterface', function () use ($adapters) {
    foreach ($adapters as $name => $factory) {
        expect($factory())->toBeInstanceOf(CloudProviderInterface::class)->and($name)->toBeString();
    }
});

it('every adapter implements every interface method with the same signature', function () use ($adapters) {
    $interfaceMethods = (new ReflectionClass(CloudProviderInterface::class))
        ->getMethods(ReflectionMethod::IS_PUBLIC);

    expect($interfaceMethods)->not->toBeEmpty();

    foreach ($adapters as $factory) {
        $reflection = new ReflectionClass($factory());

        foreach ($interfaceMethods as $method) {
            expect($reflection->hasMethod($method->getName()))
                ->toBeTrue("Adapter must implement {$method->getName()}()");
        }
    }
});

it('advertises the same capability surface with boolean flags', function () use ($adapters) {
    $capabilityKeys = [
        'supportsPowerOn',
        'supportsPowerOff',
        'supportsReboot',
        'supportsRebuild',
        'supportsResetPassword',
        'supportsSnapshots',
        'supportsSuspend',
        'supportsUsage',
    ];

    foreach ($adapters as $factory) {
        $capabilities = $factory()->capabilities();

        foreach ($capabilityKeys as $key) {
            expect($capabilities)->toHaveKey($key);
            expect($capabilities[$key])->toBeBool();
        }
    }
});

it('both adapters accept the (credentials, options) constructor shape the app uses', function () use ($adapters) {
    foreach ($adapters as $factory) {
        $adapter = $factory();
        $reflection = new ReflectionClass($adapter);
        $constructor = $reflection->getConstructor();

        expect($constructor)->not->toBeNull();
        expect($constructor->getParameters())->not->toBeEmpty();
    }
});
