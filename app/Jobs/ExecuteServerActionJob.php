<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\ServerAction;
use App\Servers\ServerActionExecutor;
use App\Servers\ServerActionLock;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Performs one recorded server action, on the provisioning queue and nowhere else.
 *
 * The queue is a correctness property. A power or delete call can block for as
 * long as a create can, and sharing a worker with interactive Telegram traffic
 * would mean every customer pressing a button waits behind somebody else's
 * machine being deleted.
 *
 * The payload is a row id. Not the action, not the server, and certainly not a
 * credential: a job payload is serialized into Redis, read by anything that can
 * reach it, and printed whole in a failed-job record.
 *
 * Running twice is safe and expected. The action's status is re-read under the
 * server's lock, and one settled action is one remote operation however many
 * jobs arrive.
 */
final class ExecuteServerActionJob implements ShouldQueue
{
    use Queueable;

    /**
     * One delivery, and then it is the reconciler's problem.
     *
     * A destructive operation that keeps retrying itself is the thing this
     * whole design avoids. Everything that did not settle is picked up by
     * `server-actions:reconcile`, which asks what happened before acting.
     */
    public int $tries = 1;

    public function __construct(public readonly int $serverActionId)
    {
        $this->onQueue(Queues::Provisioning->value);
    }

    public function handle(ServerActionExecutor $executor, ServerActionLock $locks): void
    {
        $action = ServerAction::query()->whereKey($this->serverActionId)->first();

        if (! $action instanceof ServerAction) {
            // Actions are never deleted, so this is an id that never existed.
            return;
        }

        $server = Server::query()->whereKey($action->server_id)->first();

        if (! $server instanceof Server) {
            return;
        }

        $locks->attempt($server, function () use ($executor, $action, $server): void {
            // Re-read holding the lock. Deciding from a row read before the
            // lock is deciding from the world as it was before whoever held it
            // finished — which for a delete would mean sending a second one.
            $current = ServerAction::query()->whereKey($action->getKey())->first();

            if (! $current instanceof ServerAction || ! $current->isOpen()) {
                return;
            }

            $fresh = Server::query()->whereKey($server->getKey())->first();

            if (! $fresh instanceof Server) {
                return;
            }

            $executor->execute($current, $fresh);
        });
    }

    /** The queue this job must run on, for tests and topology checks. */
    public static function queueName(): string
    {
        return Queues::Provisioning->value;
    }
}
