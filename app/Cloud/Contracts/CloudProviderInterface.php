<?php

declare(strict_types=1);

namespace App\Cloud\Contracts;

use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Exceptions\ProviderException;

/**
 * What every cloud provider must be able to do.
 *
 * Kept small on purpose. Anything not needed to sell, deliver and remove a
 * server is an optional capability instead, so that a provider lacking it
 * simply does not implement that interface rather than implementing it to
 * throw.
 *
 * Nothing provider-native crosses this boundary. Implementations return the
 * normalized objects below; an SDK model or an HTTP response leaking through
 * would put provider-shaped decisions into business code.
 *
 * Failures are thrown as ProviderException, carrying a normalized category.
 */
interface CloudProviderInterface
{
    /** Stable identifier, matching the key in the provider registry. */
    public function code(): string;

    /** Human-readable name for operator screens. */
    public function name(): string;

    /**
     * @return list<ProviderLocationData>
     *
     * @throws ProviderException
     */
    public function getLocations(): array;

    /**
     * @return list<ProviderPlanData>
     *
     * @throws ProviderException
     */
    public function getPlans(): array;

    /**
     * @return list<ProviderImageData>
     *
     * @throws ProviderException
     */
    public function getImages(): array;

    /**
     * Provider cost per plan and location.
     *
     * @return list<ProviderPricingData>
     *
     * @throws ProviderException
     */
    public function getPricing(): array;

    /**
     * Whether this plan can be created in this location right now.
     *
     * Availability changes between a customer paying and provisioning running,
     * so this is asked again immediately before creating.
     *
     * @throws ProviderException
     */
    public function checkAvailability(string $providerPlanId, string $providerLocationId): bool;

    /**
     * Create a server, or return the one this token already created.
     *
     * Implementations must look the token up first and return the existing
     * server if it is found. A repeat with the same token must never create a
     * second server, and must never reshape the existing one to match
     * different parameters.
     *
     * @throws ProviderException
     */
    public function createServer(CreateServerRequest $request): ProviderServerData;

    /**
     * @throws ProviderException When no such server exists.
     */
    public function getServer(string $providerServerId): ProviderServerData;

    /**
     * Every server this account currently has at the provider.
     *
     * Used by reconciliation to find servers the local records do not know
     * about, so it must report what the provider actually holds.
     *
     * @return list<ProviderServerData>
     *
     * @throws ProviderException
     */
    public function listServers(): array;

    /**
     * @throws ProviderException
     */
    public function deleteServer(string $providerServerId): ProviderActionData;

    /**
     * @throws ProviderException When no such action exists.
     */
    public function getAction(string $providerActionId): ProviderActionData;

    /**
     * Find the server carrying this provisioning token, if any.
     *
     * The recovery path after an uncertain create: it answers whether the
     * provider acted on a request whose response never arrived.
     *
     * @throws ProviderException
     */
    public function findByProvisioningToken(string $provisioningToken): ?ProviderServerData;
}
