<?php

declare(strict_types=1);

namespace Tests\Support\Provisioning;

use App\Cloud\Fake\FakeCatalog;
use App\Cloud\Fake\FakeProvider;

/**
 * Puts a scripted provider where the registry expects the real simulator.
 *
 * ProviderManager resolves implementations from the static registry and then
 * asks the container for that class. Binding the scripted wrapper against the
 * same class name means production code resolves it through exactly the path it
 * would use in production — no test-only branch, no interface swapped in behind
 * the manager's back.
 */
final class Simulator
{
    /**
     * Install a scripted provider and return it for configuring.
     */
    public static function script(): ScriptedProvider
    {
        $scripted = new ScriptedProvider(new FakeProvider(new FakeCatalog));

        app()->instance(FakeProvider::class, $scripted);

        return $scripted;
    }

    /** The unmodified simulator, resolved the way production resolves it. */
    public static function plain(): FakeProvider
    {
        return new FakeProvider(new FakeCatalog);
    }

    /**
     * Install a provider that offers only the core contract.
     *
     * For the tests about capabilities: an adapter declines to offer power or
     * reboot by simply not implementing the interface, and what must follow is
     * that no button appears and no request is accepted.
     */
    public static function coreOnly(): \Tests\Support\Servers\CoreOnlyProvider
    {
        $limited = new \Tests\Support\Servers\CoreOnlyProvider(new FakeProvider(new FakeCatalog));

        app()->instance(FakeProvider::class, $limited);

        return $limited;
    }
}
