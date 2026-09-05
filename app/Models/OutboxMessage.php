<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A promise to do something once the transaction that made it commits.
 *
 * Written beside the money it describes, so the two succeed or fail together.
 * A worker in a later phase reads these and delivers; nothing here sends
 * anything.
 *
 * @property string $topic
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string|null $deduplication_key
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $available_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property int $attempts
 */
class OutboxMessage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'topic', 'aggregate_type', 'aggregate_id', 'deduplication_key',
        'payload', 'available_at', 'processed_at', 'attempts',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['attempts' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
