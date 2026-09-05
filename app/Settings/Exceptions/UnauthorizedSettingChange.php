<?php

declare(strict_types=1);

namespace App\Settings\Exceptions;

use RuntimeException;

/**
 * Someone without the right to change business controls tried to change one.
 *
 * These settings decide whether the business sells and at what exchange rate,
 * so the permission that guards them is `settings.manage`, held by owners
 * alone. Support handles customers; it does not set policy.
 */
final class UnauthorizedSettingChange extends RuntimeException
{
    public static function forActor(): self
    {
        return new self('Changing a business setting requires an active administrator holding settings.manage.');
    }
}
