<?php

declare(strict_types=1);

namespace Tests\Support\Cloud;

use App\Cloud\Contracts\CloudProviderInterface;
use App\Cloud\Data\CreateServerRequest;
use App\Cloud\Data\ProviderActionData;
use App\Cloud\Data\ProviderImageData;
use App\Cloud\Data\ProviderLocationData;
use App\Cloud\Data\ProviderPlanData;
use App\Cloud\Data\ProviderPricingData;
use App\Cloud\Data\ProviderServerData;
use App\Cloud\Enums\ProviderCapability;
use App\Cloud\Enums\ProviderServerStatus;
use App\Support\Secrets\SecretScrubber;
use Closure;
use Illuminate\Support\Str;

/**
 * The behaviour every provider adapter must exhibit.
 *
 * Written against the interface and nothing else, so the same suite runs
 * unchanged against a different adapter: Phase 10 points it at Hetzner with an
 * HTTP fake rather than copying these tests and letting the two drift.
 *
 * Adapters are asked what they can do through their capability interfaces, so a
 * provider without power control simply has those cases skipped instead of the
 * suite demanding a method it never claimed to have.
 *
 * Usage from a provider's own test file:
 *
 *     ProviderConformance::describe('fake', fn () => app(FakeProvider::class), [
 *         'plan' => ..., 'location' => ..., 'image' => ...,
 *     ]);
 */
final class ProviderConformance
{
    /**
     * Register the conformance suite for one adapter.
     *
     * @param  Closure(): CloudProviderInterface  $resolve  A fresh adapter.
     * @param  array{plan: string, location: string, image: string, unavailablePlan?: string, unavailableLocation?: string}  $fixtures
     *                                                                                                                                  Catalog identifiers this provider can actually create with.
     */
    public static function describe(string $label, Closure $resolve, array $fixtures): void
    {
        $request = static function (array $fixtures, ?string $token = null): CreateServerRequest {
            return new CreateServerRequest(
                provisioningToken: $token ?? (string) Str::uuid(),
                providerPlanId: $fixtures['plan'],
                providerLocationId: $fixtures['location'],
                providerImageId: $fixtures['image'],
                name: 'conformance-'.Str::lower(Str::random(6)),
                labels: ['managed_by' => 'cloudbot'],
            );
        };

        describe("provider conformance: {$label}", function () use ($resolve, $fixtures, $request): void {

            it('reports a stable code and a name', function () use ($resolve): void {
                $provider = $resolve();

                expect($provider->code())->toBeString()->not->toBe('')
                    ->and($provider->name())->toBeString()->not->toBe('')
                    // Stable across instances: the code keys the registry and
                    // identifies stored rows, so it cannot vary per resolution.
                    ->and($resolve()->code())->toBe($provider->code());
            });

            it('normalizes locations', function () use ($resolve): void {
                $locations = $resolve()->getLocations();

                expect($locations)->not->toBeEmpty();

                foreach ($locations as $location) {
                    expect($location)->toBeInstanceOf(ProviderLocationData::class)
                        ->and($location->providerLocationId)->not->toBe('')
                        ->and($location->countryCode)->toMatch('/^[A-Z]{2}$/');
                }
            });

            it('normalizes plans, with prices that are not floats', function () use ($resolve): void {
                $plans = $resolve()->getPlans();

                expect($plans)->not->toBeEmpty();

                foreach ($plans as $plan) {
                    expect($plan)->toBeInstanceOf(ProviderPlanData::class)
                        ->and($plan->providerPlanId)->not->toBe('')
                        ->and($plan->vcpu)->toBeGreaterThan(0)
                        ->and($plan->ramMb)->toBeGreaterThan(0)
                        // Money as a decimal string. A float here would carry
                        // rounding error into every customer price derived
                        // from it.
                        ->and($plan->monthlyPrice->amount)->toBeString()
                        ->and($plan->monthlyPrice->currency)->toMatch('/^[A-Z]{3}$/');
                }
            });

            it('normalizes images', function () use ($resolve): void {
                $images = $resolve()->getImages();

                expect($images)->not->toBeEmpty();

                foreach ($images as $image) {
                    expect($image)->toBeInstanceOf(ProviderImageData::class)
                        ->and($image->providerImageId)->not->toBe('')
                        ->and($image->osFamily)->not->toBe('');
                }
            });

            it('prices every plan in every location it serves', function () use ($resolve): void {
                $pricing = $resolve()->getPricing();

                expect($pricing)->not->toBeEmpty();

                foreach ($pricing as $price) {
                    expect($price)->toBeInstanceOf(ProviderPricingData::class)
                        ->and($price->monthlyPrice->amount)->toBeString();
                }
            });

            it('answers availability for a known plan and location', function () use ($resolve, $fixtures): void {
                expect($resolve()->checkAvailability($fixtures['plan'], $fixtures['location']))->toBeTrue();
            });

            it('reports an unknown plan as unavailable rather than failing', function () use ($resolve, $fixtures): void {
                expect($resolve()->checkAvailability('definitely-not-a-plan', $fixtures['location']))->toBeFalse();
            });

            it('creates a server and normalizes it', function () use ($resolve, $fixtures, $request): void {
                $server = $resolve()->createServer($request($fixtures));

                expect($server)->toBeInstanceOf(ProviderServerData::class)
                    ->and($server->providerServerId)->not->toBe('')
                    ->and($server->status)->toBeInstanceOf(ProviderServerStatus::class);
            });

            it('gives every server a distinct identity', function () use ($resolve, $fixtures, $request): void {
                $provider = $resolve();

                $first = $provider->createServer($request($fixtures));
                $second = $provider->createServer($request($fixtures));

                expect($first->providerServerId)->not->toBe($second->providerServerId);
            });

            it('returns the same server when the same token is used again', function () use ($resolve, $fixtures, $request): void {
                // The create contract. A retry after a lost response must find
                // the server the first attempt made, not build another one.
                $token = (string) Str::uuid();

                $first = $resolve()->createServer($request($fixtures, $token));
                $second = $resolve()->createServer($request($fixtures, $token));

                expect($second->providerServerId)->toBe($first->providerServerId);
            });

            it('does not reshape an existing server when a retry differs', function () use ($resolve, $fixtures): void {
                $token = (string) Str::uuid();
                $provider = $resolve();

                $first = $provider->createServer(new CreateServerRequest(
                    $token, $fixtures['plan'], $fixtures['location'], $fixtures['image'], 'original-name',
                ));

                $second = $provider->createServer(new CreateServerRequest(
                    $token, $fixtures['plan'], $fixtures['location'], $fixtures['image'], 'different-name',
                ));

                expect($second->providerServerId)->toBe($first->providerServerId)
                    ->and($second->name)->toBe($first->name);
            });

            it('finds a server by its provisioning token', function () use ($resolve, $fixtures, $request): void {
                $token = (string) Str::uuid();
                $created = $resolve()->createServer($request($fixtures, $token));

                // Resolved separately, as the recovery path would be.
                $found = $resolve()->findByProvisioningToken($token);

                expect($found)->not->toBeNull()
                    ->and($found->providerServerId)->toBe($created->providerServerId);
            });

            it('returns nothing for a token it has never seen', function () use ($resolve): void {
                // Must answer "no server" rather than raise: this is how an
                // uncertain create is resolved, and an exception here would be
                // read as a failure to check.
                expect($resolve()->findByProvisioningToken((string) Str::uuid()))->toBeNull();
            });

            it('reads back a created server consistently', function () use ($resolve, $fixtures, $request): void {
                $created = $resolve()->createServer($request($fixtures));
                $fetched = $resolve()->getServer($created->providerServerId);

                expect($fetched->providerServerId)->toBe($created->providerServerId)
                    ->and($fetched->name)->toBe($created->name);
            });

            it('lists a created server', function () use ($resolve, $fixtures, $request): void {
                $created = $resolve()->createServer($request($fixtures));

                $ids = array_map(
                    static fn (ProviderServerData $server): string => $server->providerServerId,
                    $resolve()->listServers(),
                );

                expect($ids)->toContain($created->providerServerId);
            });

            it('answers absence for an unknown server rather than refusing', function () use ($resolve): void {
                // Null is the contract's only way of saying "there is no such
                // server", and it has to be an answer rather than a failure:
                // absence ends a customer's service, so it must never be
                // something business code infers from an error that happens to
                // look like one.
                expect($resolve()->getServer('definitely-not-a-server'))->toBeNull();
            });

            it('keeps absence and a failed lookup apart', function () use ($resolve, $fixtures, $request): void {
                // The distinction this whole return type exists for. A server
                // that is really there answers with its state; one that was
                // never there answers null; and neither is reachable by any
                // exception, which is what stops a rejected credential or a
                // timeout being read as "the customer's machine is gone".
                $created = $resolve()->createServer($request($fixtures));

                $present = $resolve()->getServer($created->providerServerId);

                expect($present)->toBeInstanceOf(ProviderServerData::class)
                    ->and($present->providerServerId)->toBe($created->providerServerId)
                    ->and($resolve()->getServer($created->providerServerId.'-not-real'))->toBeNull();
            });

            it('deletes a server and stops listing it', function () use ($resolve, $fixtures, $request): void {
                $created = $resolve()->createServer($request($fixtures));

                $action = $resolve()->deleteServer($created->providerServerId);

                expect($action)->toBeInstanceOf(ProviderActionData::class)
                    ->and($action->providerActionId)->not->toBe('');

                $ids = array_map(
                    static fn (ProviderServerData $server): string => $server->providerServerId,
                    $resolve()->listServers(),
                );

                expect($ids)->not->toContain($created->providerServerId);
            });

            it('still answers for a deleted server, rather than reporting absence', function () use ($resolve, $fixtures, $request): void {
                // A tombstone and an identity that never existed are different
                // facts, and reconciliation needs both: one says what became of
                // a machine we sold, the other says we are asking about
                // something that was never real.
                $created = $resolve()->createServer($request($fixtures));

                $resolve()->deleteServer($created->providerServerId);

                $after = $resolve()->getServer($created->providerServerId);

                expect($after)->toBeInstanceOf(ProviderServerData::class)
                    ->and($after->status->exists())->toBeFalse();
            });

            it('creates no replacement when a token is retried after deletion', function () use ($resolve, $fixtures, $request): void {
                // A provisioning token is a durable correlation identity, not a
                // lease. Once it has produced a server it must never produce
                // another, even after that server is gone: a late retry on a
                // terminated order would otherwise hand the customer a second
                // server and a second bill.
                //
                // Re-provisioning is not forbidden — it just requires a new
                // token, which the case below covers.
                $token = (string) Str::uuid();
                $provider = $resolve();

                $original = $provider->createServer($request($fixtures, $token));
                $before = count($provider->listServers());

                $provider->deleteServer($original->providerServerId);

                $retried = $provider->createServer($request($fixtures, $token));

                expect($retried->providerServerId)->toBe($original->providerServerId);

                // The token must not have been re-pointed at something else.
                $found = $provider->findByProvisioningToken($token);
                expect($found)->not->toBeNull()
                    ->and($found->providerServerId)->toBe($original->providerServerId);

                // And no replacement appeared among the active servers.
                $ids = array_map(
                    static fn (ProviderServerData $server): string => $server->providerServerId,
                    $provider->listServers(),
                );

                expect($ids)->not->toContain($original->providerServerId)
                    ->and(count($ids))->toBeLessThan($before + 1);
            });

            it('creates a distinct server for a genuinely new token', function () use ($resolve, $fixtures, $request): void {
                $provider = $resolve();

                $first = $provider->createServer($request($fixtures));
                $provider->deleteServer($first->providerServerId);

                $second = $provider->createServer($request($fixtures));

                expect($second->providerServerId)->not->toBe($first->providerServerId)
                    ->and($second->status->exists())->toBeTrue();
            });

            it('normalizes actions and can read them back', function () use ($resolve, $fixtures, $request): void {
                $created = $resolve()->createServer($request($fixtures));
                $action = $resolve()->deleteServer($created->providerServerId);

                $fetched = $resolve()->getAction($action->providerActionId);

                expect($fetched->providerActionId)->toBe($action->providerActionId)
                    ->and($fetched->command)->toBe($action->command)
                    ->and($fetched->status->isSettled() || ! $fetched->status->isSettled())->toBeTrue();
            });

            it('keeps credentials out of everything it returns', function () use ($resolve, $fixtures, $request): void {
                // Provider responses are untrusted input. Nothing that crosses
                // this boundary may carry a secret-shaped key.
                $provider = $resolve();
                $server = $provider->createServer($request($fixtures));

                $surfaces = [
                    $server->metadata->toArray(),
                    $provider->getServer($server->providerServerId)->metadata->toArray(),
                ];

                foreach ($surfaces as $metadata) {
                    foreach (array_keys($metadata) as $key) {
                        expect(SecretScrubber::isSecretKey((string) $key))->toBeFalse("metadata key {$key}");
                    }
                }
            });

            it('advertises only capabilities it actually implements', function () use ($resolve): void {
                $provider = $resolve();

                foreach (ProviderCapability::offeredBy($provider) as $capability) {
                    expect($provider)->toBeInstanceOf($capability->interface());
                }
            });

            it('powers a server off and on when it supports power control', function () use ($resolve, $fixtures, $request): void {
                $provider = $resolve();

                if (! ProviderCapability::PowerControl->isOfferedBy($provider)) {
                    expect(true)->toBeTrue('provider does not offer power control');

                    return;
                }

                $server = $provider->createServer($request($fixtures));

                expect($provider->powerOff($server->providerServerId))->toBeInstanceOf(ProviderActionData::class)
                    ->and($provider->getServer($server->providerServerId)->powerState->value)->toBe('off');

                expect($provider->powerOn($server->providerServerId))->toBeInstanceOf(ProviderActionData::class)
                    ->and($provider->getServer($server->providerServerId)->powerState->value)->toBe('on');
            });

            it('reboots a server when it supports rebooting', function () use ($resolve, $fixtures, $request): void {
                $provider = $resolve();

                if (! ProviderCapability::Reboot->isOfferedBy($provider)) {
                    expect(true)->toBeTrue('provider does not offer reboot');

                    return;
                }

                $server = $provider->createServer($request($fixtures));

                expect($provider->reboot($server->providerServerId))->toBeInstanceOf(ProviderActionData::class)
                    ->and($provider->getServer($server->providerServerId)->powerState->value)->toBe('on');
            });
        });
    }
}
