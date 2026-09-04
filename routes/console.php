<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| Scheduled tasks arrive with the features that need them. Subscription
| expiry belongs to a later phase.
|
| Neither task below does remote work itself for longer than a read: they
| claim a bounded batch and resolve it. The scheduler container runs one
| process, and a sweep that sat inside a provider's timeout would stop
| every other scheduled task behind it.
|
*/

// The safety net under provisioning. Every five minutes, because the gap it
// covers — a worker that died after a provider acted — is measured in the time
// a customer spends wondering where their server is.
Schedule::command('provisioning:reconcile')
    ->everyFiveMinutes()
    // A sweep already running is doing this work; a second would duplicate the
    // provider reads and race it for the same orders.
    ->withoutOverlapping()
    ->runInBackground();

// A financial control rather than a health check: it finds machines we pay for
// and nobody bought, and machines customers pay for that are not there. Daily
// is enough for a discrepancy that costs money by the day.
Schedule::command('providers:reconcile-inventory')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->runInBackground();
