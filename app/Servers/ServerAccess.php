<?php

declare(strict_types=1);

namespace App\Servers;

use App\Cloud\Enums\ProviderCapability;
use App\Cloud\Exceptions\ProviderException;
use App\Cloud\ProviderManager;
use App\Enums\ServerActionType;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\User;
use App\Servers\Exceptions\ServerActionNotAllowed;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The only way customer-facing code is allowed to reach a server.
 *
 * Every lookup starts from the customer. `$user->servers()->whereKey($id)`
 * cannot return somebody else's machine, whereas loading by id and checking
 * ownership afterwards is the same thing right up until one caller forgets the
 * check — and then a button carrying a number is a window into other people's
 * servers.
 *
 * Capabilities are asked of the provider implementation, never of a table
 * somebody maintains. A hand-written map of which provider can reboot drifts
 * away from the code the first time an adapter changes, and the direction it
 * drifts in is offering a button that does nothing.
 */
final readonly class ServerAccess
{
    public function __construct(private ProviderManager $providers) {}

    /**
     * One of this customer's servers, or null.
     *
     * Null covers both "not theirs" and "not a server", and callers must keep
     * it that way: two different answers would let somebody discover which ids
     * exist by watching which error they get.
     */
    public function find(User $customer, int|string $serverId): ?Server
    {
        $server = $customer->servers()
            ->with(['provider', 'subscription'])
            ->whereKey($serverId)
            ->first();

        return $server instanceof Server ? $server : null;
    }

    /**
     * @throws ServerActionNotAllowed
     */
    public function findOrFail(User $customer, int|string $serverId): Server
    {
        $server = $this->find($customer, $serverId);

        if (! $server instanceof Server) {
            throw ServerActionNotAllowed::noSuchServer();
        }

        return $server;
    }

    /**
     * This customer's servers, newest first, one page at a time.
     *
     * Paginated because a page is what a Telegram message can hold, and eager
     * loaded because a list that lazily loads a provider per row is a query
     * per server every time somebody opens the menu.
     *
     * @return LengthAwarePaginator<int, Server>
     */
    public function paginate(User $customer, int $page, int $perPage): LengthAwarePaginator
    {
        return $customer->servers()
            ->with(['provider', 'subscription'])
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * What this server's provider can actually be asked to do.
     *
     * A provider that is disabled, or whose code this build does not implement,
     * offers nothing — which is the honest answer, and stops a button appearing
     * for an operation that would fail the moment it was pressed.
     *
     * @return list<ProviderCapability>
     */
    public function capabilities(Server $server): array
    {
        try {
            return $this->providers->capabilitiesFor($this->providers->for($server->provider));
        } catch (ProviderException) {
            return [];
        }
    }

    /**
     * Whether this action can be offered for this server at all.
     *
     * Two questions, both of which must be yes: the provider has to implement
     * it, and the server has to be in a state where operating it makes sense.
     */
    public function supports(Server $server, ServerActionType $action): bool
    {
        if (! $this->isLive($server)) {
            return false;
        }

        $required = $action->requiredCapability();

        if ($action === ServerActionType::RootPasswordReveal) {
            return $server->root_password_encrypted !== null;
        }

        if ($required === null) {
            // In the core contract. Only needs a provider we can resolve.
            return $this->capabilitiesOrProviderExists($server);
        }

        return in_array($required, $this->capabilities($server), strict: true);
    }

    /**
     * @throws ServerActionNotAllowed
     */
    public function assertSupported(Server $server, ServerActionType $action): void
    {
        if (! $this->isLive($server)) {
            throw ServerActionNotAllowed::serverNotLive();
        }

        if ($action === ServerActionType::RootPasswordReveal && $server->root_password_encrypted === null) {
            throw ServerActionNotAllowed::noPasswordHeld();
        }

        if (! $this->supports($server, $action)) {
            throw ServerActionNotAllowed::unsupported($action);
        }
    }

    /** Whether operating this machine still means anything. */
    public function isLive(Server $server): bool
    {
        return $server->status !== ServerStatus::Terminated;
    }

    private function capabilitiesOrProviderExists(Server $server): bool
    {
        try {
            $this->providers->for($server->provider);

            return true;
        } catch (ProviderException) {
            return false;
        }
    }
}
