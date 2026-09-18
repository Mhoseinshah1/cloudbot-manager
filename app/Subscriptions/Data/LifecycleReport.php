<?php

declare(strict_types=1);

namespace App\Subscriptions\Data;

/**
 * What one lifecycle sweep did, for the command to print.
 *
 * Counts only: a sweep report is read in a terminal and pasted into an incident
 * note, so it carries no customer data.
 */
final class LifecycleReport
{
    public int $warned = 0;

    public int $graceEntered = 0;

    public int $terminationRequested = 0;

    /** Grace windows that closed but were deliberately not acted on. */
    public int $graceHeld = 0;
}
