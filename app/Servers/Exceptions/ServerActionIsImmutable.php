<?php

declare(strict_types=1);

namespace App\Servers\Exceptions;

use RuntimeException;

/**
 * Somebody tried to change what an action was, or whose it was.
 *
 * Refused rather than allowed-and-audited: an audit entry saying the target of
 * a delete was edited is a record of the incident, not a defence against it.
 */
final class ServerActionIsImmutable extends RuntimeException
{
    public static function cannotChange(string $attribute): self
    {
        return new self("server_actions.{$attribute} is fixed when the action is requested.");
    }
}
