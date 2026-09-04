<?php

declare(strict_types=1);

namespace App\Cloud\Enums;

/**
 * The outcome of a provider-side operation such as a reboot or a delete.
 */
enum ProviderActionStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Error = 'error';

    public function isSettled(): bool
    {
        return $this !== self::Running;
    }
}
