<?php

declare(strict_types=1);

namespace App\Cloud\Hetzner;

use App\Cloud\Capabilities\SupportsPasswordReset;
use App\Cloud\Capabilities\SupportsPowerControl;
use App\Cloud\Capabilities\SupportsReboot;
use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Data\ProviderCreateResult;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPasswordResetData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Data\SafeMetadata;
use App\Cloud\Data\SensitiveRootCredential;
use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Exceptions\ProviderException;

/**
 * Hetzner Cloud, normalized into the contract the rest of the system speaks.
 *
 * Everything above this class is provider-agnostic, and the point of the
 * adapter is to keep it that way: no Hetzner field name, status string or error
 * code appears outside `app/Cloud/Hetzner`. The reconcilers, the order flow and
 * the server actions treat this exactly as they treat the simulator.
 *
 * Three things here are load-bearing rather than incidental.
 *
 * A create is looked up by provisioning token before it is attempted, and
 * exactly one POST is ever issued. The token is committed locally before this
 * is called, so a create whose answer is lost is recoverable by asking Hetzner
 * which machine carries that label — which is the only thing standing between a
 * timeout and a customer with two servers and one invoice.
 *
 * A one-time root password is wrapped in `SensitiveRootCredential` the moment
 * it is seen and returned through the create result alone. It is never logged,
 * never put in metadata, never carried in an exception.
 *
 * Nothing here decides anything about money, subscriptions or customers. It
 * reports what the provider said; the application decides what that means.
 */
final readonly class HetznerProvider implements CloudProviderInterface, SupportsPasswordReset, SupportsPowerControl, SupportsReboot
{
    public const CODE = 'hetzner';

    public function __construct(
        private HetznerHttpClient $http,
        private HetznerNormalizer $normalizer,
        private HetznerErrorMapper $errors,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Hetzner Cloud';
    }

    /**
     * @return list<ProviderLocationData>
     */
    public function getLocations(): array
    {
        $locations = [];

        foreach ($this->http->paginate('locations', 'locations', operation: 'locations') as $row) {
            $location = $this->normalizer->location($row);

            if ($location instanceof ProviderLocationData) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * @return list<ProviderPlanData>
     */
    public function getPlans(): array
    {
        $plans = [];

        foreach ($this->serverTypes() as $type) {
            $plan = $this->normalizer->plan($type);

            if ($plan instanceof ProviderPlanData) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * @return list<ProviderImageData>
     */
    public function getImages(): array
    {
        $images = [];

        // System images only. Snapshots and backups belong to whoever made
        // them and are not an OS anybody may buy.
        $rows = $this->http->paginate('images', 'images', ['type' => 'system'], 'images');

        foreach ($rows as $row) {
            $image = $this->normalizer->image($row);

            if ($image instanceof ProviderImageData) {
                $images[] = $image;
            }
        }

        return $images;
    }

    /**
     * Every plan-and-location pair that can still be bought, with its price.
     *
     * Priced per location because Hetzner prices per location, and a single
     * headline figure would either overcharge or undercharge somewhere.
     *
     * @return list<ProviderPricingData>
     */
    public function getPricing(): array
    {
        $pricing = [];

        foreach ($this->serverTypes() as $type) {
            $planId = $type['name'] ?? null;

            if (! is_string($planId) || $planId === '') {
                continue;
            }

            foreach (HetznerNormalizer::locationPrices($type) as $price) {
                $location = $price['location'] ?? null;

                if (! is_string($location) || $location === '') {
                    continue;
                }

                if (! $this->normalizer->locationPriceIsOrderable($price)) {
                    // Withdrawn here. Publishing a price for it would be
                    // offering something the create endpoint will refuse.
                    continue;
                }

                $monthly = HetznerNormalizer::priceOf($price, 'price_monthly');

                if ($monthly === null) {
                    continue;
                }

                $pricing[] = new ProviderPricingData(
                    providerPlanId: $planId,
                    providerLocationId: $location,
                    monthlyPrice: $monthly,
                    hourlyPrice: HetznerNormalizer::priceOf($price, 'price_hourly'),
                );
            }
        }

        return $pricing;
    }

    /**
     * Whether this plan can be ordered in this location right now.
     *
     * Hetzner publishes no stock level, so this answers the question it can
     * answer honestly: the pair exists and has not been withdrawn. Actual
     * capacity is settled by the create endpoint, whose refusal normalizes to
     * `OutOfStock` — this check exists to keep an unsellable pair off the menu,
     * not to promise a machine.
     */
    public function checkAvailability(string $providerPlanId, string $providerLocationId): bool
    {
        foreach ($this->serverTypes() as $type) {
            if (($type['name'] ?? null) !== $providerPlanId) {
                continue;
            }

            foreach (HetznerNormalizer::locationPrices($type) as $price) {
                if (($price['location'] ?? null) !== $providerLocationId) {
                    continue;
                }

                return $this->normalizer->locationPriceIsOrderable($price);
            }

            return false;
        }

        return false;
    }

    /**
     * Build one server, or report the one this token already built.
     *
     * The lookup first is the whole idempotency contract. The provisioning
     * token is committed locally before this method is reachable, so if a
     * previous attempt got as far as Hetzner, the machine is out there wearing
     * that label — and creating a second one would bill a customer once for two
     * servers nobody can tell apart.
     *
     * @throws ProviderException
     */
    public function createServer(CreateServerRequest $request): ProviderCreateResult
    {
        $existing = $this->findByProvisioningToken($request->provisioningToken);

        if ($existing instanceof ProviderServerData) {
            // A replay. It establishes nothing about credentials: the password
            // belonged to the create that actually made this machine, and that
            // answer is gone.
            return ProviderCreateResult::existing($existing);
        }

        // Exactly one mutation. The client refuses to retry it, so a lost
        // answer surfaces as `UncertainResult` and the reconciler resolves it
        // through the token rather than by asking again.
        $body = $this->http->post('servers', [
            'name' => $request->name,
            'server_type' => $request->providerPlanId,
            'image' => $request->providerImageId,
            'location' => $request->providerLocationId,
            'start_after_create' => true,
            'labels' => $this->labelsFor($request),
        ], 'create_server');

        $server = $body['server'] ?? null;

        if (! is_array($server)) {
            // The machine may well exist. Saying "failed" here would be a
            // claim nobody can support.
            throw ProviderException::uncertain(
                self::CODE,
                'Hetzner accepted the create but returned no server.',
                ['operation' => 'create_server'],
            );
        }

        $normalized = $this->normalizer->server($server);

        // Wrapped the instant it is seen, and from here it can only leave
        // through the create result.
        $password = $body['root_password'] ?? null;

        $credential = is_string($password) && $password !== ''
            ? new SensitiveRootCredential($password)
            : null;

        // A create that issues no password is a legitimate normalized answer,
        // and saying so is a fact recovery depends on.
        return ProviderCreateResult::created($normalized, $credential);
    }

    /**
     * One server, or a confirmed absence.
     *
     * Null means Hetzner said this server does not exist. Nothing else becomes
     * null: an expired token or a rate limit answered with "no such server"
     * would tell the reconciler a customer's machine is gone.
     */
    public function getServer(string $providerServerId): ?ProviderServerData
    {
        try {
            $body = $this->http->get('servers/'.rawurlencode($providerServerId), operation: 'get_server');
        } catch (ProviderException $exception) {
            if ($this->isConfirmedAbsence($exception)) {
                return null;
            }

            throw $exception;
        }

        $server = $body['server'] ?? null;

        if (! is_array($server)) {
            throw $this->errors->malformed('get_server', 'the server was missing from the response.');
        }

        return $this->normalizer->server($server);
    }

    /**
     * Every server in the account, not only the ones this system made.
     *
     * Unmanaged machines are the point: inventory reconciliation exists to find
     * what we are paying for and cannot explain, and filtering by our own label
     * would hide exactly that.
     *
     * @return list<ProviderServerData>
     */
    public function listServers(): array
    {
        $servers = [];

        foreach ($this->http->paginate('servers', 'servers', operation: 'list_servers') as $row) {
            $servers[] = $this->normalizer->server($row);
        }

        return $servers;
    }

    /**
     * The machine carrying one provisioning token, if there is one.
     *
     * Asked of Hetzner with a label selector rather than by listing everything
     * and filtering here: this runs on the recovery path, where the account may
     * hold thousands of servers and the answer is needed before a customer's
     * order can move.
     *
     * Two matches is not a tie to be broken. The token is an identity, and two
     * machines wearing it means something upstream went wrong in a way that
     * picking one would bury.
     *
     * @throws ProviderException
     */
    public function findByProvisioningToken(string $provisioningToken): ?ProviderServerData
    {
        $token = trim($provisioningToken);

        if ($token === '') {
            throw ProviderException::invalidRequest(self::CODE, 'A provisioning token is required.');
        }

        $selector = HetznerNormalizer::APP_LABEL.'='.HetznerNormalizer::APP_VALUE
            .','.HetznerNormalizer::TOKEN_LABEL.'='.$token;

        $rows = $this->http->paginate('servers', 'servers', ['label_selector' => $selector], 'find_by_token');

        if ($rows === []) {
            return null;
        }

        if (count($rows) > 1) {
            throw ProviderException::make(
                ProviderErrorCategory::UncertainResult,
                self::CODE,
                'More than one Hetzner server carries this provisioning token.',
                ['operation' => 'find_by_token', 'matches' => count($rows)],
            );
        }

        $server = $this->normalizer->server($rows[0]);

        // Belt and braces: the selector is Hetzner's to honour, and delivering
        // the wrong machine to an order is unrecoverable.
        if ($server->provisioningToken !== $token) {
            throw ProviderException::make(
                ProviderErrorCategory::UncertainResult,
                self::CODE,
                'The Hetzner label selector returned a server with a different provisioning token.',
                ['operation' => 'find_by_token'],
            );
        }

        return $server;
    }

    public function powerOn(string $providerServerId): ProviderActionData
    {
        return $this->serverAction($providerServerId, 'poweron', 'power_on');
    }

    public function powerOff(string $providerServerId): ProviderActionData
    {
        // The graceful shutdown, not the power cut: `poweroff` pulls the plug
        // and a customer's filesystem pays for it.
        return $this->serverAction($providerServerId, 'shutdown', 'power_off');
    }

    public function reboot(string $providerServerId): ProviderActionData
    {
        return $this->serverAction($providerServerId, 'reboot', 'reboot');
    }

    /**
     * Delete one machine.
     *
     * One mutation, and the returned action is what settles it. A 404 is not
     * quietly turned into success here: the caller's reconciliation already
     * knows how to read an absent server, and inventing a successful delete
     * from an unrelated failure is how a live machine gets written off.
     */
    public function deleteServer(string $providerServerId): ProviderActionData
    {
        $body = $this->http->delete('servers/'.rawurlencode($providerServerId), 'delete_server');

        $action = $body['action'] ?? null;

        if (! is_array($action)) {
            throw ProviderException::uncertain(
                self::CODE,
                'Hetzner accepted the delete but returned no action.',
                ['operation' => 'delete_server'],
            );
        }

        return $this->normalizer->action($action, 'delete', $providerServerId);
    }

    /**
     * Ask what became of an action.
     *
     * The account-wide action endpoint, not the per-server one that has been
     * retired.
     */
    public function getAction(string $providerActionId): ProviderActionData
    {
        $body = $this->http->get('actions/'.rawurlencode($providerActionId), operation: 'get_action');

        $action = $body['action'] ?? null;

        if (! is_array($action)) {
            throw $this->errors->malformed('get_action', 'the action was missing from the response.');
        }

        return $this->normalizer->action($action, 'action');
    }

    /**
     * Issue a new root password for a machine that has not been delivered yet.
     *
     * Internal recovery only, exactly as ADR-003 scoped it: a create-time
     * credential can be lost between the provider acting and the local write
     * committing, and rotating an undisclosed password locks nobody out because
     * nobody has been given it. There is no customer-facing path to this, and
     * Release 1.0 does not add one.
     */
    public function resetRootPassword(string $providerServerId): ProviderPasswordResetData
    {
        $body = $this->http->post(
            'servers/'.rawurlencode($providerServerId).'/actions/reset_password',
            operation: 'reset_password',
        );

        $action = $body['action'] ?? null;

        if (! is_array($action)) {
            throw ProviderException::uncertain(
                self::CODE,
                'Hetzner accepted the password reset but returned no action.',
                ['operation' => 'reset_password'],
            );
        }

        $normalized = $this->normalizer->action($action, 'reset_password', $providerServerId);

        $password = $body['root_password'] ?? null;

        return new ProviderPasswordResetData(
            providerActionId: $normalized->providerActionId,
            providerServerId: $providerServerId,
            status: $normalized->status,
            rootCredential: is_string($password) && $password !== ''
                ? new SensitiveRootCredential($password)
                : null,
            // The action's own safe facts. Never the password.
            metadata: SafeMetadata::pick($normalized->metadata->toArray(), ['command', 'progress', 'error_code']),
        );
    }

    /**
     * One server action: one POST, one normalized answer, no waiting.
     *
     * A provider call that blocked until the machine finished rebooting would
     * hold a worker and a lock for the length of somebody else's boot. The
     * action id comes back, and the reconciler polls it.
     */
    private function serverAction(string $providerServerId, string $endpoint, string $command): ProviderActionData
    {
        $body = $this->http->post(
            'servers/'.rawurlencode($providerServerId).'/actions/'.$endpoint,
            operation: $command,
        );

        $action = $body['action'] ?? null;

        if (! is_array($action)) {
            throw ProviderException::uncertain(
                self::CODE,
                'Hetzner accepted the action but returned no action record.',
                ['operation' => $command],
            );
        }

        return $this->normalizer->action($action, $command, $providerServerId);
    }

    /**
     * The labels a created server carries.
     *
     * The two reserved labels are applied last so a caller-supplied label can
     * never overwrite them: the token label is the machine's identity, and
     * losing it to a stray key would make the server unfindable and the order
     * unrecoverable.
     *
     * @return array<string, string>
     */
    private function labelsFor(CreateServerRequest $request): array
    {
        $labels = [];

        foreach ($request->labels as $key => $value) {
            if (! is_string($key) || $value === null) {
                continue;
            }

            if ($key === HetznerNormalizer::TOKEN_LABEL || $key === HetznerNormalizer::APP_LABEL) {
                continue;
            }

            $labels[$key] = (string) $value;
        }

        $labels[HetznerNormalizer::APP_LABEL] = HetznerNormalizer::APP_VALUE;
        $labels[HetznerNormalizer::TOKEN_LABEL] = $request->provisioningToken;

        return $labels;
    }

    /**
     * Whether this failure is Hetzner confirming a server does not exist.
     *
     * Deliberately narrow. The mapper gives a 404 `InvalidRequest` rather than
     * absence, and only a caller that asked for one named server may read it
     * as "gone" — which is what this does, and only when Hetzner also said so
     * in its own machine-readable code.
     */
    private function isConfirmedAbsence(ProviderException $exception): bool
    {
        return $exception->category === ProviderErrorCategory::InvalidRequest
            && ($exception->context['http_status'] ?? null) === 404
            && ($exception->context['hetzner_code'] ?? null) === 'not_found';
    }

    /**
     * Server types, read once per operation.
     *
     * @return list<array<string, mixed>>
     */
    private function serverTypes(): array
    {
        return $this->http->paginate('server_types', 'server_types', operation: 'server_types');
    }
}
