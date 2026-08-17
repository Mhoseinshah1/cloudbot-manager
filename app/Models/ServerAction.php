<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerAction extends Model
{
    use HasFactory;

    public const ACTION_POWER_ON = 'power_on';

    public const ACTION_POWER_OFF = 'power_off';

    public const ACTION_REBOOT = 'reboot';

    public const ACTION_REBUILD = 'rebuild';

    public const ACTION_RESET_PASSWORD = 'reset_password';

    public const ACTION_RENEW = 'renew';

    public const ACTION_DELETE = 'delete';

    public const ACTION_SUSPEND = 'suspend';

    public const ACTION_UNSUSPEND = 'unsuspend';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'server_id',
        'user_id',
        'action',
        'status',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
