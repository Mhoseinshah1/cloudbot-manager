<?php

namespace App\Jobs;

use App\Contracts\Data\ProviderImageData;
use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPlanData;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\ProviderException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderPlan;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditService;
use App\Services\HourlyBillingService;
use App\Services\ProviderManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class ProvisionServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public Order $order) {}

    public function handle(ProviderManager $manager, AuditService $audit, HourlyBillingService $billing): void
    {
        /** @var Order|null $order */
        $order = $this->order->fresh(['user', 'product.provider', 'product.providerPlan', 'items']);

        // Idempotency guard: only provision paid orders that have no server yet.
        if ($order === null || $order->status !== Order::STATUS_PAID || $order->server()->exists()) {
            return;
        }

        $lock = Cache::lock('provision:'.$order->id, 120);

        if (! $lock->get()) {
            throw new RuntimeException('Provisioning is already in progress for order '.$order->id);
        }

        try {
            $order->refresh();

            if ($order->status !== Order::STATUS_PAID || $order->server()->exists()) {
                return;
            }

            /** @var Product $product */
            $product = $order->product;
            /** @var Provider $provider */
            $provider = $product->provider;
            /** @var ProviderPlan $plan */
            $plan = $product->providerPlan;

            $isHourly = $product->billingMode()->isHourly();

            // Hourly services are prepaid: provisioning is rejected (before
            // the provider is called and before the order state changes) when
            // the wallet cannot cover the configured minimum prepaid balance.
            // The initial payment is wallet funding — never a usage charge.
            if ($isHourly) {
                $user = $order->user;

                if (! $user instanceof User) {
                    throw new RuntimeException('Order has no user; cannot verify the minimum prepaid balance.');
                }

                $billing->assertMinimumPrepaid($user, (int) ($product->hourly_price_toman ?? 0));
            }

            $order->update(['status' => Order::STATUS_PROVISIONING]);

            $planData = $provider->plans()->where('provider_plan_id', $plan->provider_plan_id)->first()
                ?? throw ProviderException::unavailable('Plan', $plan->provider_plan_id);

            $location = $provider->locations()->where('enabled', true)->first()
                ?? throw ProviderException::unavailable('Location', 'enabled');

            // Deprecated/unavailable provider images are never offered for provisioning.
            $image = $provider->images()->where('enabled', true)->whereNull('deprecated')->first()
                ?? throw ProviderException::unavailable('Image', 'enabled');

            // The DTOs must carry provider-facing ids (provider_plan_id,
            // provider_location_id, provider_image_id) — never local primary keys.
            $planDto = ProviderPlanData::fromArray([...$planData->toArray(), 'id' => $planData->provider_plan_id]);
            $locationDto = ProviderLocationData::fromArray([...$location->toArray(), 'id' => $location->provider_location_id]);
            $imageDto = ProviderImageData::fromArray([...$image->toArray(), 'id' => $image->provider_image_id]);

            $serverName = Str::slug($product->name).'-'.substr($order->order_number, -6);

            // Idempotency label: lets reconciliation find an already-created
            // server if a create call was retried after an uncertain response.
            $provisioningUuid = (string) Str::uuid();

            $serverData = $manager->resolve($provider)->createServer(
                plan: $planDto,
                image: $imageDto,
                location: $locationDto,
                name: $serverName,
                options: [
                    'labels' => [
                        'app' => 'vps-platform',
                        'provisioning-uuid' => $provisioningUuid,
                    ],
                ],
            );

            [$appStatus, $powerState] = $this->mapProviderState($serverData->status);

            /** @var array<string, mixed> $cost */
            $cost = $order->cost_snapshot ?? [];

            $server = Server::query()->create([
                'user_id' => $order->user_id,
                'order_id' => $order->id,
                'product_id' => $product->id,
                'provider_id' => $provider->id,
                'provider_server_id' => $serverData->id,
                'name' => $serverName,
                'ip_address' => $serverData->ipAddress,
                'provider_location_id' => $location->id,
                'plan_snapshot' => $planData->toArray(),
                'image_snapshot' => $image->toArray(),
                'provider_metadata' => array_merge($serverData->metadata, [
                    'provisioning_uuid' => $provisioningUuid,
                    'provider_status' => $serverData->status,
                ]),
                'status' => $appStatus,
                'power_state' => $powerState,
                'billing_mode' => $product->billingMode()->value,
                'hourly_rate_toman' => $product->hourly_price_toman,
                'monthly_cap_toman' => $product->monthly_cap_toman,
                'provider_cost' => $cost['provider_cost'] ?? null,
                'provider_currency' => $cost['provider_currency'] ?? null,
                'exchange_rate' => $cost['exchange_rate'] ?? null,
                'local_cost' => $cost['local_cost'] ?? null,
                'selling_price' => $cost['selling_price'] ?? null,
                'gross_margin' => $cost['gross_margin'] ?? null,
                'root_password_encrypted' => $serverData->rootPassword,
                'expires_at' => $isHourly ? null : $this->expiryFor($product->billing_cycle),
            ]);

            $subscription = Subscription::query()->create([
                'user_id' => $order->user_id,
                'server_id' => $server->id,
                'product_id' => $product->id,
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => $isHourly ? now()->addMonth() : $server->expires_at,
                'price_toman' => $order->total_toman,
                'billing_cycle' => $product->billing_cycle,
                'billing_mode' => $product->billingMode()->value,
                'hourly_rate_toman' => $product->hourly_price_toman,
                'monthly_cap_toman' => $product->monthly_cap_toman,
            ]);

            // Hourly customer billing begins the moment provisioning succeeds.
            if ($isHourly) {
                $billing->startBilling($server, $subscription);
            }

            $order->update([
                'status' => Order::STATUS_PROVISIONED,
                'provisioned_at' => now(),
            ]);

            $audit->record('server.provisioned', $server, $order->user, after: [
                'provider_server_id' => $server->provider_server_id,
                'ip_address' => $server->ip_address,
                'order_number' => $order->order_number,
            ]);
        } catch (InsufficientWalletBalanceException $e) {
            // The wallet fell below the minimum prepaid requirement. The
            // order returns to paid so a later retry succeeds once the
            // customer tops up. No provider call was made.
            $audit->record('provision.insufficient_balance', $order, $order->user, after: [
                'order_number' => $order->order_number,
            ]);

            throw $e;
        } catch (ProviderException $e) {
            $audit->record('provision.failed', $order, $order->user, after: [
                'reason' => $e->getMessage(),
                'order_number' => $order->order_number,
            ]);

            $order->refresh();
            if ($order->status === Order::STATUS_PROVISIONING) {
                $order->update(['status' => Order::STATUS_PAID]);
            }

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        $order = $this->order->fresh();

        if ($order !== null) {
            app(AuditService::class)->record('provision.failed_final', $order, after: [
                'reason' => $e->getMessage(),
                'order_number' => $order->order_number,
            ]);
        }
    }

    private function expiryFor(string $billingCycle): Carbon
    {
        return match ($billingCycle) {
            'quarterly' => now()->addMonths(3),
            'yearly' => now()->addYear(),
            default => now()->addMonth(),
        };
    }

    /**
     * Maps provider-neutral power state to application server status.
     *
     * @return array{0: string, 1: string}
     */
    private function mapProviderState(string $providerStatus): array
    {
        return match ($providerStatus) {
            'off', 'stopping', 'stopped' => [Server::STATUS_OFF, 'off'],
            'initializing', 'starting', 'rebuilding', 'migrating' => [Server::STATUS_PROVISIONING, 'running'],
            'deleting' => [Server::STATUS_DELETING, 'off'],
            default => [Server::STATUS_RUNNING, 'running'],
        };
    }
}
