<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to change history.
 */
final class AuditLogIsAppendOnly extends RuntimeException
{
    public static function cannotUpdate(): self
    {
        return new self('Audit log entries are append-only and cannot be updated.');
    }

    public static function cannotDelete(): self
    {
        return new self('Audit log entries are append-only and cannot be deleted.');
    }
}
