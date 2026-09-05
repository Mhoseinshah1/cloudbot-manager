<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditLog;
use App\Support\Secrets\SecretScrubber;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way to write an audit entry.
 *
 * Centralised for two reasons. Every payload is scrubbed of credentials on the
 * way in, which cannot be guaranteed if callers write rows themselves; and the
 * shape of an entry stays consistent, so an investigation can rely on the same
 * fields meaning the same thing across every event.
 */
final class AuditRecorder
{
    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     * @param  array<array-key, mixed>|null  $metadata
     */
    public function record(
        string $event,
        ?Model $actor = null,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'event' => $event,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $this->scrub($before),
            'after' => $this->scrub($after),
            'metadata' => $this->scrub($metadata),
        ]);
    }

    /**
     * Record an action taken from the console, where there is no logged-in
     * actor but the operator who ran it is still worth naming.
     *
     * @param  array<array-key, mixed>|null  $metadata
     */
    public function recordFromConsole(
        string $event,
        ?Model $subject = null,
        ?array $metadata = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'event' => $event,
            'actor_type' => 'console',
            'actor_id' => null,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $this->scrub($metadata),
        ]);
    }

    /**
     * @param  array<array-key, mixed>|null  $values
     * @return array<array-key, mixed>|null
     */
    private function scrub(?array $values): ?array
    {
        return $values === null ? null : SecretScrubber::scrub($values);
    }
}
