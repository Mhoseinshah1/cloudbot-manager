<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationLog;

/**
 * What became of one attempt to tell somebody something.
 *
 * Two facts, kept apart because they answer different questions. The log row
 * says what happened and becomes support history; `finished` says whether the
 * intent behind it is done with.
 *
 * They come apart in exactly one case, and it is the one that matters: an
 * operational alert with no destination configured is recorded honestly as
 * undeliverable, and is emphatically not finished. Marking it finished would
 * discard a durable alert because of a configuration gap — and configuring the
 * channel an hour later would then deliver nothing, because the intent that
 * needed delivering had already been marked done.
 */
final readonly class DeliveryOutcome
{
    private function __construct(
        public NotificationLog $log,
        /** Whether the intent behind this delivery needs nothing further. */
        public bool $finished,
        /** Seconds to wait before trying again, when not finished. */
        public int $retryAfterSeconds = 0,
    ) {}

    /** Delivered, refused, or skipped because it was already delivered. */
    public static function settled(NotificationLog $log): self
    {
        return new self($log, finished: true);
    }

    /**
     * Nothing was sent because there is nowhere configured to send it.
     *
     * Not a failed delivery: no request was made, nothing was refused, and the
     * missing piece is configuration rather than anything about the message.
     */
    public static function deferred(NotificationLog $log, int $retryAfterSeconds): self
    {
        return new self($log, finished: false, retryAfterSeconds: max(1, $retryAfterSeconds));
    }
}
