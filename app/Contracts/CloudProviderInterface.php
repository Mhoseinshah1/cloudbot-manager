<?php

namespace App\Contracts;

use App\Contracts\Data\ProviderActionData;
use App\Contracts\Data\ProviderImageData;
use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPlanData;
use App\Contracts\Data\ProviderPricingData;
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

    /**
     * Per-location pricing snapshot (e.g. GET /pricing on Hetzner).
     *
     * @return array<int, ProviderPricingData>
     */
    public function getPricing(): array;

    /**
     * Creates a server and, when the provider reports an asynchronous
     * creation action, waits for it and confirms the final provisioning
     * state before returning. HTTP success alone is never treated as
     * final provider success.
     */
    public function createServer(
        ProviderPlanData $plan,
        ProviderImageData $image,
        ProviderLocationData $location,
        string $name,
        array $options = []
    ): ProviderServerData;

    public function getServer(string $providerServerId): ProviderServerData;

    /**
     * Executes the action and returns the normalized, final provider action
     * (after waiting for completion where the provider is asynchronous).
     */
    public function powerOn(string $providerServerId): ProviderActionData;

    public function powerOff(string $providerServerId): ProviderActionData;

    public function reboot(string $providerServerId): ProviderActionData;

    public function rebuild(string $providerServerId, ProviderImageData $image): ProviderActionData;

    /**
     * Resets the root password and returns the new one. Credentials are
     * returned exactly once and must be stored encrypted by the caller.
     */
    public function resetPassword(string $providerServerId): string;

    public function deleteServer(string $providerServerId): void;

    public function getUsage(string $providerServerId): ProviderUsageData;

    /**
     * Fetches a single asynchronous action by its provider id.
     */
    public function getAction(string $actionId): ProviderActionData;

    /**
     * Polls an asynchronous action until it reaches success/error, the
     * timeout elapses, or the polling budget is exhausted. Bounded —
     * never an infinite loop.
     */
    public function waitForAction(string $actionId, int $timeoutSeconds = 300, int $pollingIntervalMs = 2000): ProviderActionData;

    /**
     * Finds a server previously created with the given label key/value,
     * or null. Used for idempotent creation: an uncertain create response
     * must never cause a second server creation automatically.
     */
    public function findServerByLabel(string $key, string $value): ?ProviderServerData;
}
