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

    /** Nothing knows how to deliver this. Left visible for a person. */
    public static function unhandled(): self
    {
        return new self(finished: false);
    }

    public static function from(DeliveryOutcome $outcome): self
    {
        return $outcome->finished
            ? self::finished()
            : self::deferred($outcome->retryAfterSeconds);
    }

    public function isDeferred(): bool
    {
        return ! $this->finished && $this->deferSeconds > 0;
    }
}
