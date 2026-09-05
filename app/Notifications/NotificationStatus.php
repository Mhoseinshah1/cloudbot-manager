<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * How a delivery attempt ended.
 *
 * `Blocked` is separate from `Failed` on purpose. A customer blocking the bot
 * is their decision and retrying cannot change it, whereas a failure is
 * something that might work next time — collapsing the two would either retry
 * somebody's refusal forever or give up on a transient error.
 *
 * `Undeliverable` means there was nowhere to send it: an admin channel nobody
 * configured, or a customer with no chat. Also not a failure to retry, and also
 * not something to record as sent.
 */
enum NotificationStatus: string
{
    case Sent = 'sent';

    case Failed = 'failed';

    /** The recipient blocked the bot. */
    case Blocked = 'blocked';

    /** There is no configured destination to send to. */
    case Undeliverable = 'undeliverable';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
