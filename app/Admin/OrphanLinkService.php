<?php

declare(strict_types=1);

namespace App\Admin;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\ProviderServerData;
use App\Enums\Permission;
use App\Enums\ServerStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Provider;
use App\Models\Server;
use App\Models\User;
use App\Provisioning\OrderPlanner;
use App\Provisioning\ReconciliationService;
use App\Provisioning\ServerPersister;
use App\Provisioning\ProvisioningService;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Attaches a remote machine nobody can explain to the order that paid for it.
 *
 * The last-resort recovery tool, and the most dangerous thing in the panel. An
 * orphan is a server the provider is charging us for with no local record —
 * usually a create whose local write never committed — and the fix is to give
 * it back to the order that bought it. Get it wrong and a customer is handed
 * somebody else's machine, with their data on it.
 *
 * So every claim is re-established here rather than taken from the form. The
 * remote machine is read from the provider again, the order is checked for an
 * existing server, the provider must match, the provisioning token must agree
 * where the machine carries one, and ownership comes from the order rather than
 * from anything an operator typed. Anything ambiguous is refused outright:
 * "investigate this by hand" is a far better outcome than a confident link to
 * the wrong customer.
 *
 * Persistence goes through the same `ServerPersister` the ordinary provisioning
 * path uses, so a linked server is indistinguishable from a normally delivered
 * one and the one-order-one-server invariant is enforced by the same code.
 */
final readonly class OrphanLinkService
{
    public function __construct(
        private ReconciliationService $reconciliation,
        private ProvisioningService $provisioning,
        private OrderPlanner $planner,
        private ServerPersister $persister,
        private AuditRecorder $audit,
    ) {}

    /**
     * What the operator will be shown before they confirm.
     *
     * Read fresh from the provider, because the point of the screen is to let
     * a person compare the real machine against the real order.
     *
     * @throws RuntimeException when the pairing must not be offered at all.
     */
    public function preview(Provider $provider, string $providerServerId, Order $order): ProviderServerData
    {
        $driver = $this->reconciliation->readableProvider($provider->code);

        if (! $driver instanceof CloudProviderInterface) {
            throw new RuntimeException('That provider has no readable implementation.');
        }

        $remote = $driver->getServer($providerServerId);

        if (! $remote instanceof ProviderServerData) {
            // Null here is a confirmed absence, which means there is nothing
            // to link and the orphan list is stale.
            throw new RuntimeException('The provider no longer holds a server with that identifier.');
        }

        $this->assertLinkable($provider, $order, $remote);

        return $remote;
    }

    /**
     * Link the machine to the order, once an operator has confirmed it.
     *
     * @throws RuntimeException when any invariant would be broken.
     */
    public function link(
        Provider $provider,
        string $providerServerId,
        Order $order,
        User $operator,
        string $reason,
    ): Server {
        if (! $operator->isActive() || ! $operator->checkPermissionTo(Permission::InventoryManage->value)) {
            throw new RuntimeException('You may not link provider inventory.');
        }

        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required to link an orphaned server.');
        }

        // Read again at the moment of acting, not from the preview the operator
        // has been looking at. The provider call happens here, outside any
        // transaction, and nothing below opens one around a network call.
        $remote = $this->preview($provider, $providerServerId, $order);

        $prepared = $this->provisioning->prepare($order->fresh() ?? $order);

        if (! $prepared instanceof Order) {
            throw new RuntimeException('That order is not in a state that can receive a server.');
        }

        $plan = $this->planner->plan($prepared);

        // The same persistence the ordinary delivery path uses: one order, one
        // server, one subscription, in one transaction, with the unique
        // constraints doing the real work. No credential is passed, because
        // this recovery has none and inventing one would be worse than null.
        $server = $this->persister->persist($prepared, $remote, $plan, CarbonImmutable::now());

        $this->audit->record(
            AuditEvent::InventoryOrphanLinked,
            actor: $operator,
            subject: $server,
            metadata: [
                'server_id' => $server->getKey(),
                'order_id' => $prepared->getKey(),
                // Ownership is the order's, never the operator's to choose.
                'user_id' => $prepared->user_id,
                'provider_id' => $provider->getKey(),
                'provider_server_id' => $remote->providerServerId,
                'provisioning_uuid' => $remote->provisioningToken,
                'reason' => mb_substr(trim($reason), 0, 500),
            ],
        );

        return $server;
    }

    /**
     * Every reason this pairing must not happen.
     *
     * @throws RuntimeException
     */
    private function assertLinkable(Provider $provider, Order $order, ProviderServerData $remote): void
    {
        $existing = Server::query()
            ->where('provider_id', $provider->getKey())
            ->where('provider_server_id', $remote->providerServerId)
            ->first();

        if ($existing instanceof Server && $existing->status !== ServerStatus::Terminated) {
            // Not an orphan at all: some order already owns this machine.
            throw new RuntimeException('That provider server is already linked to a local server record.');
        }

        if (Server::query()->where('order_id', $order->getKey())->exists()) {
            // One order, one server. Linking a second would give a customer a
            // machine they never bought and break the invariant everything
            // downstream relies on.
            throw new RuntimeException('That order already has a server.');
        }

        $product = Product::query()->whereKey($order->product_id)->first();

        if (! $product instanceof Product || (int) $product->provider_id !== (int) $provider->getKey()) {
            // The order's provider comes from its product, which is where the
            // purchase actually decided whose account this machine lives in.
            throw new RuntimeException('That order was placed against a different provider.');
        }

        $token = $remote->provisioningToken;

        if ($token !== null && $token !== $order->provisioning_uuid) {
            // The machine is wearing somebody else's token. This is exactly
            // the case where a confident answer is the dangerous one.
            throw new RuntimeException(
                'That machine carries a provisioning token belonging to a different order. Investigate manually.',
            );
        }
    }
}
