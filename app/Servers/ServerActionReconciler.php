<?php

declare(strict_types=1);

namespace App\Servers;

use App\Enums\ServerActionStatus;
use App\Models\Server;
use App\Models\ServerAction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Finds actions nobody finished, and asks the provider what happened.
 *
 * Necessary because a provider call and a database write cannot be one atomic
 * step. A worker can delete a machine and die before recording it, and the only
 * account of that is an action stuck at running with a customer still being
 * shown a server that no longer exists.
 *
 * Bounded on purpose. A batch limit stops one sweep pulling an unbounded table
 * into memory, and the attempt cap on each action stops a destructive request
 * being sent over and over — a reconciler that hammered a delete endpoint until
 * it worked would be worse than the drift it is fixing.
 *
 * A provider that cannot be read is never treated as an answer. Leaving an
 * action open is the correct outcome of not knowing.
 */
final readonly class ServerActionReconciler
{
    public function __construct(
        private ServerActionExecutor $executor,
        private ServerActionLock $locks,
        private Config $config,
    ) {}

    /**
     * Look at every unsettled action that has had time to settle on its own.
     *
     * @return int How many were examined.
     */
    public function sweep(): int
    {
        $examined = 0;

        foreach ($this->stale() as $action) {
            if ($this->reconcile($action)) {
                $examined++;
            }
        }

        return $examined;
    }

    /**
     * Reconcile one action.
     *
     * @return bool Whether it was actually examined, as opposed to skipped
     *              because another worker held the server.
     */
    public function reconcile(ServerAction $action): bool
    {
        $server = Server::query()->whereKey($action->server_id)->first();

        if (! $server instanceof Server) {
            return false;
        }

        $handled = $this->locks->attempt($server, function () use ($action, $server): bool {
            // Re-read holding the lock: what was true when the batch was
            // selected is not what is true now.
            $current = ServerAction::query()->whereKey($action->getKey())->first();

            if (! $current instanceof ServerAction || ! $current->isOpen()) {
                return true;
            }

            $fresh = Server::query()->whereKey($server->getKey())->first();

            if (! $fresh instanceof Server) {
                return true;
            }

            $this->executor->poll($current, $fresh);

            return true;
        });

        return $handled === true;
    }

    /**
     * Unsettled actions old enough to be worth asking about.
     *
     * The delay matters: an action requested a second ago is probably in a
     * worker's hands right now, and two processes calling one provider about
     * one machine is exactly what the lock exists to prevent.
     *
     * @return \Illuminate\Support\Collection<int, ServerAction>
     */
    public function stale(): \Illuminate\Support\Collection
    {
        return ServerAction::query()
            ->whereIn('status', [ServerActionStatus::Pending->value, ServerActionStatus::Running->value])
            ->where('requested_at', '<=', CarbonImmutable::now()->subSeconds($this->delaySeconds()))
            ->orderBy('id')
            ->limit($this->batchSize())
            ->get();
    }

    private function delaySeconds(): int
    {
        return max(1, (int) $this->config->get('cloudbot.server_actions.reconcile_after_seconds', 60));
    }

    private function batchSize(): int
    {
        return max(1, (int) $this->config->get('cloudbot.server_actions.reconcile_batch', 100));
    }
}
