<?php

declare(strict_types=1);

namespace App\Cloud\Capabilities;

use App\Cloud\Data\ProviderActionData;

/**
 * The provider can switch a server on and off.
 *
 * Optional so that a provider without it simply does not implement this
 * interface, rather than implementing it to throw. Whether a capability is
 * offered is answered by `instanceof`, never by a list someone maintains
 * by hand.
 */
interface SupportsPowerControl
{
    public function powerOn(string $providerServerId): ProviderActionData;

    public function powerOff(string $providerServerId): ProviderActionData;
}
