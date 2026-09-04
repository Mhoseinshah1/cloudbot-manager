<?php

declare(strict_types=1);

namespace App\Enums;

use App\Cloud\Enums\ProviderPowerState;

/**
 * Whether we last saw this server running.
 *
 * Separate from status, because a server can exist and be switched off, and
 * separate from the provider's own enum so that a provider adding a case cannot
 * silently widen what this column may hold.
 *
 * `Unknown` is a real answer and the honest default: we know what the provider
 * said when we last asked, not what is true now.
 */
enum ServerPowerState: string
{
    case On = 'on';
    case Off = 'off';
    case Unknown = 'unknown';

    /** Translate the provider's report into our own vocabulary. */
    public static function fromProvider(ProviderPowerState $state): self
    {
        return match ($state) {
            ProviderPowerState::On => self::On,
            ProviderPowerState::Off => self::Off,
            ProviderPowerState::Unknown => self::Unknown,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
