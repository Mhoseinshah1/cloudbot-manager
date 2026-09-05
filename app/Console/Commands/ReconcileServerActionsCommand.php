<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Servers\ServerActionReconciler;
use Illuminate\Console\Command;

/**
 * Asks providers what became of actions nobody finished.
 *
 * Run every few minutes. A worker can delete a machine and die before recording
 * it, and the only account of that is an action stuck at running while a
 * customer is still shown a server that no longer exists.
 *
 * Bounded: one batch per run, and each action has a durable attempt cap. A
 * reconciler that hammered a delete endpoint until it worked would be worse
 * than the drift it exists to fix.
 */
final class ReconcileServerActionsCommand extends Command
{
    protected $signature = 'server-actions:reconcile';

    protected $description = 'Reconcile server actions that have not settled';

    public function handle(ServerActionReconciler $reconciler): int
    {
        $examined = $reconciler->sweep();

        $this->info($examined === 0
            ? 'No unsettled server actions.'
            : "Examined {$examined} unsettled server action(s).");

        return self::SUCCESS;
    }
}
