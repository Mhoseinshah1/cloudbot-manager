<?php

namespace App\Contracts;

use App\Contracts\Data\ProviderImageData;
use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPlanData;
use App\Contracts\Data\ProviderServerData;
use App\Contracts\Data\ProviderUsageData;

interface CloudProviderInterface
{
    /**
     * Machine-readable provider code (fake, hetzner, vultr, ...).
     */
    public function code(): string;

    /**
     * Human-readable provider name.
     */
    public function name(): string;

    /**
     * Capability flags. The application must never assume every
     * provider supports every action.
     *
     * @return array{supportsPowerOn: bool, supportsPowerOff: bool, supportsReboot: bool, supportsRebuild: bool, supportsResetPassword: bool, supportsSnapshots: bool, supportsSuspend: bool, supportsUsage: bool}
     */
    public function capabilities(): array;

    /**
     * @return array<int, ProviderLocationData>
     */
    public function getLocations(): array;

    /**
     * @return array<int, ProviderPlanData>
     */
    public function getPlans(): array;

    /**
     * @return array<int, ProviderImageData>
     */
    public function getImages(): array;

    public function createServer(
        ProviderPlanData $plan,
        ProviderImageData $image,
        ProviderLocationData $location,
        string $name,
        array $options = []
    ): ProviderServerData;

    public function getServer(string $providerServerId): ProviderServerData;

    public function powerOn(string $providerServerId): void;

    public function powerOff(string $providerServerId): void;

    public function reboot(string $providerServerId): void;

    public function rebuild(string $providerServerId, ProviderImageData $image): void;

    /**
     * Resets the root password and returns the new one. Credentials are
     * returned exactly once and must be stored encrypted by the caller.
     */
    public function resetPassword(string $providerServerId): string;

    public function deleteServer(string $providerServerId): void;

    public function getUsage(string $providerServerId): ProviderUsageData;
}
