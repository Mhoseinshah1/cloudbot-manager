<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Notifications\DeliveryOutcome;

/**
 * How one outbox intent stands after a worker looked at it.
 *
 * A boolean was not enough, and the case that proved it is an operational alert
 * with no destination configured. That is neither done — nobody was told, and
 * the alert is the whole point — nor a failure to retry immediately, because
 * only a person configuring a channel will change the answer. Marked done, the
 * alert is silently discarded; retried at once, it spins.
 *
 * So there are three answers: finished, deferred until a stated time, and
 * unhandled. Only the first lets a row be marked processed.
 */
final readonly class OutboxDisposition
{
    private function __construct(
        public bool $finished,
        public int $deferSeconds = 0,
        /** Whether the attempt this consumed should stay consumed. */
        public bool $attemptWasMade = false,
    ) {}

    public static function finished(): self
    {
        return new self(finished: true);
    }

    /**
     * Nothing was done, and doing it now would not help.
     *
     * The attempt this consumed is given back by the caller: a deferral is not
     * a delivery attempt, and letting configuration absence eat a finite retry
     * budget means configuring the channel later delivers nothing.
     */
    public static function deferred(int $seconds): self
    {
        return new self(finished: false, deferSeconds: max(1, $seconds));
    }

    /**
     * A request was made and refused. Wait, but keep the attempt.
     *
     * The counterpart to deferred(), and deliberately not the same answer. Both
     * leave the row unprocessed and push its not-before time out; only this one
     * charges the delivery budget, because something really was sent and really
     * was rejected. Handing that attempt back would let a permanently
     * misconfigured channel retry without bound.
     */
    public static function postponed(int $seconds): self
    {
        return new self(finished: false, deferSeconds: max(1, $seconds), attemptWasMade: true);
    }

    /** Nothing knows how to deliver this. Left visible for a person. */
    public static function unhandled(): self
    {
        return new self(finished: false);
    }

    public static function from(DeliveryOutcome $outcome): self
    {
        if ($outcome->finished) {
            return self::finished();
        }

        return $outcome->attemptWasMade
            ? self::postponed($outcome->retryAfterSeconds)
            : self::deferred($outcome->retryAfterSeconds);
    }

    /** Unfinished, delayed, and owed its attempt back. */
    public function isDeferred(): bool
    {
        return ! $this->finished && $this->deferSeconds > 0 && ! $this->attemptWasMade;
    }

    /** Unfinished, delayed, and keeping the attempt it spent. */
    public function isPostponed(): bool
    {
        return ! $this->finished && $this->deferSeconds > 0 && $this->attemptWasMade;
    }
}
