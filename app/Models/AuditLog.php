<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\AuditLogIsAppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the append-only audit trail.
 *
 * Write through AuditRecorder rather than constructing this directly: the
 * recorder scrubs credentials out of the payload, and that guarantee is worth
 * nothing if some call sites bypass it.
 *
 * @property string $event
 * @property array<array-key, mixed>|null $before
 * @property array<array-key, mixed>|null $after
 * @property array<array-key, mixed>|null $metadata
 */
class AuditLog extends Model
{
    /**
     * Rows are written once and never touched again, so there is no
     * `updated_at` to maintain.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event',
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'before',
        'after',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Refuse mutation in the application layer.
     *
     * The database refuses it too. Both exist on purpose: this one gives a
     * clear error at the call site, and the database one still holds for code
     * paths that never load this model.
     */
    protected static function booted(): void
    {
        static::updating(static function (self $log): never {
            throw AuditLogIsAppendOnly::cannotUpdate();
        });

        static::deleting(static function (self $log): never {
            throw AuditLogIsAppendOnly::cannotDelete();
        });
    }
}
