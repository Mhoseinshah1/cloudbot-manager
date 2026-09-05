<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The queues this system dispatches onto.
 *
 * Queue names are defined here once so that workers, jobs and the Compose
 * topology cannot drift apart. Each queue is drained by its own worker:
 * interactive bot work must never wait behind a slow provider call.
 */
enum Queues: string
{
    /** Interactive Telegram work. Short jobs, short timeout. */
    case Telegram = 'telegram';

    /** Provider provisioning. Long timeout, low concurrency. */
    case Provisioning = 'provisioning';

    /** Outbound customer and admin notifications. */
    case Notifications = 'notifications';

    /** Anything not claimed by a dedicated queue. */
    case Default = 'default';

    /**
     * Queue names in the order the notification worker drains them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $queue): string => $queue->value, self::cases());
    }
}
