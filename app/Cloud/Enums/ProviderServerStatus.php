<?php

declare(strict_types=1);

namespace App\Cloud\Enums;

/**
 * A remote server's lifecycle state, normalized across providers.
 */
enum ProviderServerStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Deleting = 'deleting';
    case Deleted = 'deleted';
    case Error = 'error';

    /** Whether the provider still considers this server to exist. */
    public function exists(): bool
    {
        return $this !== self::Deleted;
    }
}
