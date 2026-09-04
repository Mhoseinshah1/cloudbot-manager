<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Models\OutboxMessage;
use App\Support\Secrets\SecretScrubber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Records work to be done after the current transaction commits.
 *
 * Call this inside the transaction that makes the thing true. That is the whole
 * point: the promise to tell a customer about their refund and the refund
 * itself either both survive or both disappear, and a customer is never told
 * about money that a rollback took back.
 *
 * Nothing here sends anything.
 */
final readonly class OutboxWriter
{
    /**
     * Write an intent, or return the one already written.
     *
     * The deduplication key is what makes a retried business decision produce
     * one message rather than two. Enforced by a unique index, so two
     * concurrent transactions cannot both insert; the loser finds the winner's
     * row and returns it.
     *
     * @param  array<string, mixed>  $payload  Facts only — ids, amounts, names.
     *                                         Scrubbed on the way in, because a
     *                                         payload is eventually rendered
     *                                         into a message to a person.
     */
    public function record(
        string $topic,
        Model $aggregate,
        array $payload,
        ?string $deduplicationKey = null,
    ): OutboxMessage {
        $attributes = [
            'topic' => $topic,
            'aggregate_type' => $aggregate->getMorphClass(),
            'aggregate_id' => (string) $aggregate->getKey(),
            'deduplication_key' => $deduplicationKey,
            'payload' => SecretScrubber::scrub($payload),
            'available_at' => now(),
        ];

        if ($deduplicationKey === null) {
            return OutboxMessage::query()->create($attributes);
        }

        $existing = $this->find($deduplicationKey);

        if ($existing instanceof OutboxMessage) {
            return $existing;
        }

        try {
            // In a savepoint: a losing insert would otherwise abort the
            // caller's transaction, taking the refund down with it.
            return DB::transaction(fn (): OutboxMessage => OutboxMessage::query()->create($attributes));
        } catch (QueryException $exception) {
            $winner = $this->find($deduplicationKey);

            if ($winner instanceof OutboxMessage) {
                return $winner;
            }

            throw $exception;
        }
    }

    private function find(string $deduplicationKey): ?OutboxMessage
    {
        $message = OutboxMessage::query()->where('deduplication_key', $deduplicationKey)->first();

        return $message instanceof OutboxMessage ? $message : null;
    }
}
