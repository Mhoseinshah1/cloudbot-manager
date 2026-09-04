<?php

declare(strict_types=1);

namespace App\Cloud\Fake\Models;

use App\Cloud\Enums\ProviderActionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * An operation inside the simulated provider.
 *
 * Kept after the server it refers to is deleted, the way a real provider's
 * action history is: the record of a deletion is most useful once the server
 * is gone.
 *
 * @property string $provider_action_id
 * @property string $command
 * @property string|null $provider_server_id
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property ProviderActionStatus $status
 * @property array<string, mixed>|null $metadata
 */
class FakeProviderAction extends Model
{
    protected $table = 'fake_provider_actions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_action_id', 'command', 'status', 'provider_server_id',
        'started_at', 'finished_at', 'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProviderActionStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
