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
 * They come apart whenever an alert nobody received must survive to be received
 * later, and there are two such cases. They are kept distinct because they owe
 * the durable attempt budget different things.
 *
 * An operational alert with no destination configured is recorded honestly as
 * undeliverable and is emphatically not finished. Marking it finished would
 * discard a durable alert because of a configuration gap — and configuring the
 * channel an hour later would then deliver nothing, because the intent that
 * needed delivering had already been marked done. Nothing left the building, so
 * the attempt it consumed is handed back.
 *
 * An operational alert the administrator channel *refused* — Telegram answering
 * 403 for a chat that is configured — is also not finished, for the same reason:
 * fixing the chat's permissions tomorrow has to deliver the alert that was
 * already waiting. But a request genuinely was made and genuinely was refused,
 * so that attempt stays spent. That refusal is emphatically not a customer
 * blocking the bot: a customer's 403 is a person's decision and is terminal,
 * while an operator channel's 403 is a configuration fault somebody will fix.
 */
final readonly class DeliveryOutcome
{
    private function __construct(
        public NotificationLog $log,
        /** Whether the intent behind this delivery needs nothing further. */
        public bool $finished,
        /** Seconds to wait before trying again, when not finished. */
        public int $retryAfterSeconds = 0,
        /**
         * Whether an external request was actually made.
         *
         * Decides who pays for an unfinished delivery. A refusal spends the
         * attempt it used; a missing destination never made a request and gets
         * its attempt back, because a configuration gap must not exhaust the
         * retry budget of an alert nobody has yet had the chance to receive.
         */
        public bool $attemptWasMade = false,
    ) {}

    /** Delivered, terminally refused, or skipped because it was already delivered. */
    public static function settled(NotificationLog $log): self
    {
        return new self($log, finished: true, attemptWasMade: true);
    }

    /**
     * The destination is configured and refused us. Try again later.
     *
     * For an administrator channel answering 403, where the alert must survive
     * until an operator can receive it but the attempt genuinely happened and
     * is not refunded.
     */
    public static function refused(NotificationLog $log, int $retryAfterSeconds): self
    {
        return new self(
            $log,
            finished: false,
            retryAfterSeconds: max(1, $retryAfterSeconds),
            attemptWasMade: true,
        );
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
