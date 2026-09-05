<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How far one requested server action has got.
 *
 * `Running` means a provider accepted the request and is still working on it,
 * which is a different thing from `Pending` — pending work may never have been
 * sent, and that distinction decides whether it is safe to send it again.
 *
 * `NeedsAttention` is where an action goes when nobody can honestly say what
 * happened at the provider. It is not failure: a failed action can be retried,
 * and retrying something that may already have rebooted or deleted a machine is
 * the outcome this state exists to prevent.
 */
enum ServerActionStatus: string
{
    /** Recorded, not yet sent. Also where a crashed worker leaves it. */
    case Pending = 'pending';

    /** The provider accepted it and is still working. */
    case Running = 'running';

    case Succeeded = 'succeeded';

    /** It definitely did not happen. Safe to ask again. */
    case Failed = 'failed';

    /** Nobody knows whether it happened. A person decides. */
    case NeedsAttention = 'needs_attention';

    /** Whether anything more is owed on this action. */
    public function isSettled(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** Whether a worker should still be looking at this. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Running;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
