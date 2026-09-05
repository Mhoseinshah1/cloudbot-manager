<?php

declare(strict_types=1);

namespace Tests\Support\Provisioning;

use App\Cloud\Capabilities\SupportsPowerControl;
use App\Cloud\Capabilities\SupportsReboot;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\Fake\FakeProvider;
use Closure;

/**
 * The real simulator, wrapped so a test can make one call misbehave.
 *
 * Every method delegates to FakeProvider, so the persistent PostgreSQL-backed
 * state, the unique index on the provisioning token and the delete tombstone all
 * still apply. Only the behaviour a test explicitly scripts is different.
 *
 * That matters for the tests this exists for. "The provider created a server and
 * then the response was lost" is only a real test if a server genuinely was
 * created — a mock that throws without writing anything proves nothing about
 * recovery, because there is nothing to recover.
 */
final class ScriptedProvider implements CloudProviderInterface, SupportsPowerControl, SupportsReboot
{
    /** @var list<string> */
    public array $calls = [];

    private ?Closure $afterCreate = null;

    private ?Closure $beforeCreate = null;

    private ?Closure $onAvailability = null;

    private ?Closure $onListServers = null;

    /** @var array<string, Closure> Keyed by the operation being scripted. */
    private array $onOperation = [];

    public function __construct(private readonly FakeProvider $inner) {}

    /**
     * Run this after a create has genuinely happened.
     *
     * The server exists in the simulator's database by the time the callback
     * runs, so throwing here reproduces the one failure that matters most: a
     * remote machine nobody local knows about.
     *
     * @param  Closure(ProviderServerData): ProviderServerData  $callback
     */
    public function afterCreate(Closure $callback): self
    {
        $this->afterCreate = $callback;

        return $this;
    }

    /**
     * Run this instead of creating anything.
     *
     * @param  Closure(CreateServerRequest): mixed  $callback
     */
    public function beforeCreate(Closure $callback): self
    {
        $this->beforeCreate = $callback;

        return $this;
    }

    /**
     * @param  Closure(string, string): bool  $callback
     */
    public function onAvailability(Closure $callback): self
    {
        $this->onAvailability = $callback;

        return $this;
    }

    /**
     * @param  Closure(list<ProviderServerData>): list<ProviderServerData>  $callback
     */
    public function onListServers(Closure $callback): self
    {
        $this->onListServers = $callback;

        return $this;
    }

    /**
     * Run this instead of one server operation.
     *
     * The operations that matter here are the ones whose failure is ambiguous:
     * a reboot that timed out may or may not have restarted a customer's
     * machine, and only a scripted provider can produce that reliably.
     *
     * @param  Closure(string): mixed  $callback
     */
    public function onOperation(string $operation, Closure $callback): self
    {
        $this->onOperation[$operation] = $callback;

        return $this;
    }

    /** Make one operation fail in a way nobody can interpret. */
    public function loseResponseFor(string $operation, string $message = 'The response never arrived.'): self
    {
        return $this->onOperation($operation, static function () use ($message): never {
            throw ProviderException::uncertain(FakeProvider::CODE, $message);
        });
    }

    /** Make one operation fail definitely, in a way that is safe to retry. */
    public function rejectOperation(string $operation, ProviderErrorCategory $category, string $message = 'Refused.'): self
    {
        return $this->onOperation($operation, static function () use ($category, $message): never {
            throw ProviderException::make($category, FakeProvider::CODE, $message);
        });
    }

    /** Throw this category from the next create, having created nothing. */
    public function rejectCreate(ProviderErrorCategory $category, string $message = 'Refused.'): self
    {
        return $this->beforeCreate(static function () use ($category, $message): never {
            throw ProviderException::make($category, FakeProvider::CODE, $message);
        });
    }

    /** Create the server for real, then lose the response. */
    public function loseCreateResponse(string $message = 'The response never arrived.'): self
    {
        return $this->afterCreate(static function (ProviderServerData $server) use ($message): never {
            throw ProviderException::uncertain(FakeProvider::CODE, $message);
        });
    }

    public function callCount(string $method): int
    {
        return count(array_filter($this->calls, static fn (string $call): bool => $call === $method));
    }

    public function code(): string
    {
        return $this->inner->code();
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    /**
     * @return list<ProviderLocationData>
     */
    public function getLocations(): array
    {
        return $this->inner->getLocations();
    }

    /**
     * @return list<ProviderPlanData>
     */
    public function getPlans(): array
    {
        return $this->inner->getPlans();
    }

    /**
     * @return list<ProviderImageData>
     */
    public function getImages(): array
    {
        return $this->inner->getImages();
    }

    /**
     * @return list<ProviderPricingData>
     */
    public function getPricing(): array
    {
        return $this->inner->getPricing();
    }

    public function checkAvailability(string $providerPlanId, string $providerLocationId): bool
    {
        $this->calls[] = 'checkAvailability';

        if ($this->onAvailability instanceof Closure) {
            return (bool) ($this->onAvailability)($providerPlanId, $providerLocationId);
        }

        return $this->inner->checkAvailability($providerPlanId, $providerLocationId);
    }

    public function createServer(CreateServerRequest $request): ProviderServerData
    {
        $this->calls[] = 'createServer';

        if ($this->beforeCreate instanceof Closure) {
            // Nothing is created. Whatever the callback does is the outcome.
            $result = ($this->beforeCreate)($request);

            if ($result instanceof ProviderServerData) {
                return $result;
            }
        }

        $server = $this->inner->createServer($request);

        if ($this->afterCreate instanceof Closure) {
            // The server is already in the simulator's database.
            return ($this->afterCreate)($server);
        }

        return $server;
    }

    public function getServer(string $providerServerId): ?ProviderServerData
    {
        $this->intercept('getServer');

        $this->calls[] = 'getServer';

        return $this->inner->getServer($providerServerId);
    }

    /**
     * @return list<ProviderServerData>
     */
    public function listServers(): array
    {
        $this->calls[] = 'listServers';

        $servers = $this->inner->listServers();

        if ($this->onListServers instanceof Closure) {
            return array_values(($this->onListServers)($servers));
        }

        return $servers;
    }

    public function powerOn(string $providerServerId): ProviderActionData
    {
        $this->calls[] = 'powerOn';
        $this->intercept('powerOn');

        return $this->inner->powerOn($providerServerId);
    }

    public function powerOff(string $providerServerId): ProviderActionData
    {
        $this->calls[] = 'powerOff';
        $this->intercept('powerOff');

        return $this->inner->powerOff($providerServerId);
    }

    public function reboot(string $providerServerId): ProviderActionData
    {
        $this->calls[] = 'reboot';
        $this->intercept('reboot');

        return $this->inner->reboot($providerServerId);
    }

    /**
     * Run a scripted operation hook, if one is set.
     *
     * Consumed on use, so a test scripts one failure rather than an endless
     * one — an operation that could never succeed would prove only that a
     * retry loop stops, never that recovery works.
     */
    private function intercept(string $operation): void
    {
        $hook = $this->onOperation[$operation] ?? null;

        if ($hook === null) {
            return;
        }

        unset($this->onOperation[$operation]);

        $hook($operation);
    }

    public function deleteServer(string $providerServerId): ProviderActionData
    {
        $this->intercept('deleteServer');

        $this->calls[] = 'deleteServer';

        return $this->inner->deleteServer($providerServerId);
    }

    public function getAction(string $providerActionId): ProviderActionData
    {
        return $this->inner->getAction($providerActionId);
    }

    public function findByProvisioningToken(string $provisioningToken): ?ProviderServerData
    {
        $this->calls[] = 'findByProvisioningToken';

        return $this->inner->findByProvisioningToken($provisioningToken);
    }
}
