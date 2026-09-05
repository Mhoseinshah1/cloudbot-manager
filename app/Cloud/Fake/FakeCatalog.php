<?php

declare(strict_types=1);

namespace App\Cloud\Fake;

use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPrice;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Data\SafeMetadata;

/**
 * The simulated provider's fixed catalog.
 *
 * Deterministic so tests can assert on it and local development looks the same
 * every run. Prices are the simulated provider's own cost in EUR; they are not
 * customer prices, and nothing here implies a selling price or a margin.
 *
 * One plan is deliberately out of stock in one location, because availability
 * changing between payment and provisioning is a real path that needs a way to
 * be exercised.
 */
final class FakeCatalog
{
    public const LOCATION_PRIMARY = 'fake-fsn1';

    public const LOCATION_SECONDARY = 'fake-hel1';

    public const PLAN_SMALL = 'fake-cx11';

    public const PLAN_LARGE = 'fake-cx41';

    public const IMAGE_UBUNTU = 'fake-ubuntu-24.04';

    public const IMAGE_DEBIAN = 'fake-debian-12';

    /** The combination the simulator always reports as sold out. */
    private const OUT_OF_STOCK = [self::PLAN_LARGE.'@'.self::LOCATION_SECONDARY];

    /**
     * @return list<ProviderLocationData>
     */
    public function locations(): array
    {
        return [
            new ProviderLocationData(
                self::LOCATION_PRIMARY, 'Falkenstein', 'DE', 'Falkenstein',
                true, SafeMetadata::pick(['network_zone' => 'eu-central'], ['network_zone']),
            ),
            new ProviderLocationData(
                self::LOCATION_SECONDARY, 'Helsinki', 'FI', 'Helsinki',
                true, SafeMetadata::pick(['network_zone' => 'eu-central'], ['network_zone']),
            ),
        ];
    }

    /**
     * @return list<ProviderPlanData>
     */
    public function plans(): array
    {
        return [
            new ProviderPlanData(
                self::PLAN_SMALL, 'Fake CX11', 1, 2048, 20, 20_000,
                ProviderPrice::of('4.510000', 'EUR'),
                ProviderPrice::of('0.007000', 'EUR'),
                SafeMetadata::pick(['cpu_type' => 'shared'], ['cpu_type']),
            ),
            new ProviderPlanData(
                self::PLAN_LARGE, 'Fake CX41', 4, 16384, 160, 20_000,
                ProviderPrice::of('26.900000', 'EUR'),
                ProviderPrice::of('0.043000', 'EUR'),
                SafeMetadata::pick(['cpu_type' => 'shared'], ['cpu_type']),
            ),
        ];
    }

    /**
     * @return list<ProviderImageData>
     */
    public function images(): array
    {
        return [
            new ProviderImageData(
                self::IMAGE_UBUNTU, 'Ubuntu 24.04', 'ubuntu', '24.04', 'x86',
                false, SafeMetadata::empty(),
            ),
            new ProviderImageData(
                self::IMAGE_DEBIAN, 'Debian 12', 'debian', '12', 'x86',
                true, SafeMetadata::empty(),
            ),
        ];
    }

    /**
     * Cost per plan and location.
     *
     * The larger plan costs more in the secondary location, so that code which
     * assumes one price per plan is caught rather than accidentally correct.
     *
     * @return list<ProviderPricingData>
     */
    public function pricing(): array
    {
        $pricing = [];

        foreach ($this->plans() as $plan) {
            foreach ($this->locations() as $location) {
                $premium = $location->providerLocationId === self::LOCATION_SECONDARY
                    && $plan->providerPlanId === self::PLAN_LARGE;

                $pricing[] = new ProviderPricingData(
                    $plan->providerPlanId,
                    $location->providerLocationId,
                    $premium ? ProviderPrice::of('28.900000', 'EUR') : $plan->monthlyPrice,
                    $plan->hourlyPrice,
                );
            }
        }

        return $pricing;
    }

    public function hasPlan(string $providerPlanId): bool
    {
        foreach ($this->plans() as $plan) {
            if ($plan->providerPlanId === $providerPlanId) {
                return true;
            }
        }

        return false;
    }

    public function hasLocation(string $providerLocationId): bool
    {
        foreach ($this->locations() as $location) {
            if ($location->providerLocationId === $providerLocationId) {
                return true;
            }
        }

        return false;
    }

    public function hasImage(string $providerImageId): bool
    {
        foreach ($this->images() as $image) {
            if ($image->providerImageId === $providerImageId) {
                return true;
            }
        }

        return false;
    }

    public function isAvailable(string $providerPlanId, string $providerLocationId): bool
    {
        if (! $this->hasPlan($providerPlanId) || ! $this->hasLocation($providerLocationId)) {
            return false;
        }

        return ! in_array($providerPlanId.'@'.$providerLocationId, self::OUT_OF_STOCK, true);
    }
}
