<?php

declare(strict_types=1);

namespace App\Cloud\Enums;

use App\Cloud\Capabilities\SupportsPasswordReset;
use App\Cloud\Capabilities\SupportsPowerControl;
use App\Cloud\Capabilities\SupportsReboot;
use App\Cloud\Contracts\CloudProviderInterface;

/**
 * Optional provider capabilities, and the interface each one means.
 *
 * This enum names capabilities; it does not record which provider has them.
 * That question is answered by asking the adapter itself, so the answer cannot
 * drift away from the code the way a hand-maintained boolean map does.
 *
 * Capabilities whose behaviour belongs to a later release are absent entirely:
 * advertising one before it works would be a promise the system cannot keep.
 */
enum ProviderCapability: string
{
    case PowerControl = 'power_control';
    case Reboot = 'reboot';

    /**
     * Issuing a new root password for a server the provider already runs.
     *
     * Present in Release 1.0 for one internal purpose: recovering a
     * provisioning credential lost before delivery. It backs no customer-facing
     * reset flow, and advertising it does not imply one. See ADR-003.
     */
    case PasswordReset = 'password_reset';

    /**
     * The interface a provider must implement to offer this capability.
     *
     * @return class-string
     */
    public function interface(): string
    {
        return match ($this) {
            self::PowerControl => SupportsPowerControl::class,
            self::Reboot => SupportsReboot::class,
            self::PasswordReset => SupportsPasswordReset::class,
        };
    }

    public function isOfferedBy(CloudProviderInterface $provider): bool
    {
        return $provider instanceof ($this->interface());
    }

    /**
     * Everything this adapter actually implements.
     *
     * @return list<self>
     */
    public static function offeredBy(CloudProviderInterface $provider): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $capability): bool => $capability->isOfferedBy($provider),
        ));
    }
}
