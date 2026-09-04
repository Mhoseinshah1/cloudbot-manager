<?php

declare(strict_types=1);

namespace Tests\Support\Cache;

use Illuminate\Cache\Lock;

/**
 * A lock that is always free, and tells its store the moment it is taken.
 *
 * Always granting is what makes the test deterministic: the contention being
 * reproduced is not two workers wanting the lock at once — that case is already
 * covered — but one worker taking a lock the other has just finished with, and
 * then deciding from information older than that hand-over.
 */
final class HookedLock extends Lock
{
    public function __construct(
        private readonly HookedLockStore $store,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        $this->store->acquired($this->name);

        return true;
    }

    public function release(): bool
    {
        return true;
    }

    public function forceRelease(): void
    {
        // Always free; there is nothing to take back.
    }

    protected function getCurrentOwner(): string
    {
        return $this->owner;
    }
}
