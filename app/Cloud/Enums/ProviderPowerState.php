<?php

declare(strict_types=1);

namespace App\Cloud\Enums;

/**
 * Whether a remote server is running.
 *
 * Separate from status: a server can exist and be switched off. Powering off
 * is never proof that the provider has stopped charging for it.
 */
enum ProviderPowerState: string
{
    case On = 'on';
    case Off = 'off';
    case Unknown = 'unknown';
}
