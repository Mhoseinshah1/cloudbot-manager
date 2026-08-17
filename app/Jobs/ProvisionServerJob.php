<?php

namespace App\Jobs;

use App\Contracts\Data\ProviderImageData;
use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPlanData;
use App\Exceptions\ProviderException;
use App\Models\Order;
use App\Models\Server;
use App\Models\Subscription;
use App\Services\AuditService;
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

    public function handle(ProviderManager $manager, AuditService $audit): void
    {
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

            $order->update(['status' => Order::STATUS_PROVISIONING]);

            $product = $order->product;
            $provider = $product->provider;
            $plan = $product->providerPlan;

            $planData = $provider->plans()->where('provider_plan_id', $plan->provider_plan_id)->first()
                ?? throw ProviderException::unavailable('Plan', $plan->provider_plan_id);

            $location = $provider->locations()->where('enabled', true)->first()
                ?? throw ProviderException::unavailable('Location', 'enabled');

            $image = $provider->images()->where('enabled', true)->first()
                ?? throw ProviderException::unavailable('Image', 'enabled');

            $serverName = Str::slug($product->name).'-'.substr($order->order_number, -6);

            $serverData = $manager->resolve($provider)->createServer(
                plan: ProviderPlanData::fromArray($planData->toArray()),
                image: ProviderImageData::fromArray($image->toArray()),
                location: ProviderLocationData::fromArray($location->toArray()),
                name: $serverName,
            );

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
                'status' => Server::STATUS_RUNNING,
                'power_state' => 'running',
                'provider_cost' => $cost['provider_cost'] ?? null,
                'provider_currency' => $cost['provider_currency'] ?? null,
                'exchange_rate' => $cost['exchange_rate'] ?? null,
                'local_cost' => $cost['local_cost'] ?? null,
                'selling_price' => $cost['selling_price'] ?? null,
                'gross_margin' => $cost['gross_margin'] ?? null,
                'root_password_encrypted' => $serverData->rootPassword,
                'expires_at' => $this->expiryFor($product->billing_cycle),
            ]);

            Subscription::query()->create([
                'user_id' => $order->user_id,
                'server_id' => $server->id,
                'product_id' => $product->id,
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now(),
                'current_period_end' => $server->expires_at,
                'price_toman' => $order->total_toman,
                'billing_cycle' => $product->billing_cycle,
            ]);

            $order->update([
                'status' => Order::STATUS_PROVISIONED,
                'provisioned_at' => now(),
            ]);

            $audit->record('server.provisioned', $server, $order->user, after: [
                'provider_server_id' => $server->provider_server_id,
                'ip_address' => $server->ip_address,
                'order_number' => $order->order_number,
            ]);
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
}
