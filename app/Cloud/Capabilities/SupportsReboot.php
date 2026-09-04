<?php

declare(strict_types=1);

namespace App\Cloud\Capabilities;

use App\Cloud\Data\ProviderActionData;

/**
 * The provider can restart a server.
 */
interface SupportsReboot
{
    public function reboot(string $providerServerId): ProviderActionData;
}
