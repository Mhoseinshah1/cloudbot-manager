<?php

declare(strict_types=1);

namespace App\Models;

use App\Cloud\Enums\ProviderErrorCategory;
use App\Enums\ServerActionStatus;
use App\Enums\ServerActionType;
use App\Exceptions\FinancialRecordDeletionForbidden;
use App\Servers\Exceptions\ServerActionIsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody asked us to do to one server.
 *
 * The row is written before a provider is called and outlives the answer, which
 * is what makes it useful: an action that is recorded and then crashes is
 * something a reconciler can pick up, while an action performed and then
 * recorded is something nobody knows happened.
 *
 * Who asked, of what, and for what are frozen — by this model and by a trigger.
 * An operation whose target could be edited afterwards would be no account of
 * anything, and a request that could be rewritten into a different action is
 * how a reboot in the history becomes a delete nobody authorized.
 *
 * @property int $server_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property ServerActionType $action
 * @property ServerActionStatus $status
 * @property string $idempotency_key
 * @property string|null $provider_action_id
 * @property ProviderErrorCategory|null $error_category
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $requested_at
 * @property \Illuminate\Support\Carbon|null $settled_at
 * @property int $attempts
 * @property \Illuminate\Support\Carbon|null $retry_after
 * @property-read Server $server
 */
class ServerAction extends Model
{
    /** The actor kind recorded when a customer asked for this themselves. */
    public const ACTOR_CUSTOMER = 'customer';

    /** The actor kind recorded when a sweep or reconciler acted. */
    public const ACTOR_SYSTEM = 'system';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_id', 'actor_type', 'actor_id', 'action', 'status', 'idempotency_key',
        'provider_action_id', 'error_category', 'metadata', 'requested_at', 'settled_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ServerActionStatus::Pending->value,
        'attempts' => 0,
    ];

    /**
     * Decided when the action is requested. The database enforces this too.
     *
     * @var list<string>
     */
    public const IMMUTABLE = ['server_id', 'actor_type', 'actor_id', 'action', 'idempotency_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ServerActionType::class,
            'status' => ServerActionStatus::class,
            'error_category' => ProviderErrorCategory::class,
            'metadata' => 'array',
            'requested_at' => 'datetime',
            'settled_at' => 'datetime',
            'retry_after' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * Whether a worker may reach the provider for this action right now.
     *
     * The barrier is durable and per action, not per queue delivery. A job that
     * was already waiting in Redis when a rate limit was written has to honour
     * it too, and releasing the worker that received the refusal says nothing
     * about that one.
     */
    public function mayAttemptNow(): bool
    {
        return $this->retry_after === null || ! $this->retry_after->isFuture();
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * Refuse the two things that would make this history untrustworthy:
     * erasing it, and rewriting whose action it was.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $action): never {
            throw FinancialRecordDeletionForbidden::forServerAction();
        });

        static::updating(static function (self $action): void {
            foreach (self::IMMUTABLE as $attribute) {
                if ($action->isDirty($attribute)) {
                    throw ServerActionIsImmutable::cannotChange($attribute);
                }
            }
        });
    }
}
