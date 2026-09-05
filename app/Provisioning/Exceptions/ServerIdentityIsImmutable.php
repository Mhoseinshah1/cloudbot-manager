<?php

declare(strict_types=1);

namespace App\Provisioning\Exceptions;

use RuntimeException;

/**
 * Something tried to change which machine a server record means, or what it
 * cost.
 *
 * The realistic caller is not malice, it is reconciliation. A sweep reads a
 * provider's inventory and writes back what it found; a provider that recycles
 * an identifier, answers for the wrong account, or reports a plan that has
 * since been renamed would, without this, quietly move a machine to a different
 * customer or restate the price they were charged.
 *
 * Correcting an address is synchronization. Changing whose server it is, or
 * what it cost, is a different act and needs a person.
 */
final class ServerIdentityIsImmutable extends RuntimeException
{
    private function __construct(public readonly string $attribute, string $message)
    {
        parent::__construct($message);
    }

    public static function cannotChange(string $attribute): self
    {
        return new self(
            $attribute,
            "A server's {$attribute} is fixed when it is delivered and cannot be changed.",
        );
    }
}
