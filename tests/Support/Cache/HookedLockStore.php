<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock as LockContract;

/**
 * A lock store that lets a test decide what happens at the moment of acquisition.
 *
 * The race this exists for cannot be reproduced by timing. It needs one worker
 * to finish an update in the instant between another worker reading the row and
 * that other worker taking the lock — a window measured in microseconds, which
 * a sleep can only guess at. So the window is made explicit instead: the hook
 * runs inside acquire(), which is exactly that instant, and a test that puts
 * "worker A finishes and commits" in the hook reproduces the race every single
 * run.
 */
class HookedLockStore extends ArrayStore
{
    /** @var Closure(string): void */
    private Closure $onAcquire;

    /** @var list<string> */
    private array $acquired = [];

    /**
     * @param  Closure(string): void  $onAcquire  Runs as the lock is taken.
     */
    public function __construct(Closure $onAcquire)
    {
        parent::__construct();

        $this->onAcquire = $onAcquire;
    }

    /**
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     */
    public function lock($name, $seconds = 0, $owner = null): LockContract
    {
        return new HookedLock($this, $name, $seconds, $owner);
    }

    /** Called by the lock as it is acquired, never before. */
    public function acquired(string $name): void
    {
        $this->acquired[] = $name;

        ($this->onAcquire)($name);
    }

    /**
     * The lock names taken so far, so a test can prove the job really locked.
     *
     * @return list<string>
     */
    public function acquiredNames(): array
    {
        return $this->acquired;
    }
}
