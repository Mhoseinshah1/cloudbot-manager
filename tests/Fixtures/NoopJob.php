<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A queueable job that does nothing.
 *
 * Used to prove queue storage behaviour. Phase 1 defines no real jobs yet.
 */
final class NoopJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}
