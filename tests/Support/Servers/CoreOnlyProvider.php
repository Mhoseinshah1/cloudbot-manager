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
    public function __construct(private readonly FakeProvider $inner) {}

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
        return $this->inner->createServer($request);
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
