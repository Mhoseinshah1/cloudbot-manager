<?php

declare(strict_types=1);

namespace Tests\Support\Servers;

use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Data\ProviderCreateResult;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Fake\FakeProvider;

/**
 * A provider that sells and deletes servers and cannot do anything else.
 *
 * The capability interfaces are simply absent, which is exactly how a real
 * adapter declines to offer something: not a method that throws, and not a flag
 * in a table somebody maintains. Existing to prove the buttons follow the code
 * — a reboot button drawn for this provider would be a promise the system
 * cannot keep, and a reboot accepted for it would be worse.
 */
final class CoreOnlyProvider implements CloudProviderInterface
{
    /** @var list<string> */
    public array $calls = [];

    private bool $issuesCredentials = true;

    private ?\Closure $afterCreate = null;

    public function __construct(private readonly FakeProvider $inner) {}

    /**
     * Model a provider that authenticates some other way.
     *
     * A valid shape: the create builds a machine and issues no root password,
     * and there is no reset capability because there is nothing to reset. The
     * disposition still says `Created`, which is what makes the answer a
     * complete fact rather than a silence.
     */
    public function withoutCredential(): self
    {
        $this->issuesCredentials = false;

        return $this;
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
        return $this->inner->checkAvailability($providerPlanId, $providerLocationId);
    }

    public function createServer(CreateServerRequest $request): ProviderCreateResult
    {
        $this->calls[] = 'createServer';

        $created = $this->inner->createServer($request);

        if ($this->issuesCredentials || ! $created->isNew()) {
            return $created;
        }

        if ($this->afterCreate !== null) {
            return ($this->afterCreate)($created->server);
        }

        return ProviderCreateResult::created($created->server);
    }

    /**
     * Reshape the create answer, the way ScriptedProvider does.
     *
     * @param  \Closure(ProviderServerData): ProviderCreateResult  $callback
     */
    public function afterCreate(\Closure $callback): self
    {
        $this->issuesCredentials = false;
        $this->afterCreate = $callback;

        return $this;
    }

    public function getServer(string $providerServerId): ?ProviderServerData
    {
        return $this->inner->getServer($providerServerId);
    }

    /**
     * @return list<ProviderServerData>
     */
    public function listServers(): array
    {
        return $this->inner->listServers();
    }

    public function deleteServer(string $providerServerId): ProviderActionData
    {
        return $this->inner->deleteServer($providerServerId);
    }

    public function getAction(string $providerActionId): ProviderActionData
    {
        return $this->inner->getAction($providerActionId);
    }

    public function findByProvisioningToken(string $provisioningToken): ?ProviderServerData
    {
        return $this->inner->findByProvisioningToken($provisioningToken);
    }
}
