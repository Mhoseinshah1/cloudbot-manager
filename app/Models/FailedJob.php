<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Laravel's own failed-jobs table, given a model so it can be listed.
 *
 * Read-only from the application's point of view: retrying and forgetting go
 * through `queue:retry` and `queue:forget`, which own the payload format. The
 * serialized payload column is deliberately excluded from `$fillable` and never
 * selected for display — it holds whatever a job was carrying.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Kept out of arrays and JSON: a job payload is not for a browser.
     *
     * @var list<string>
     */
    protected $hidden = ['payload', 'connection'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }
}
