<?php

declare(strict_types=1);

namespace App\Provisioning;

use App\Enums\BillingCycle;
use App\Enums\BillingMode;
use App\Models\Order;
use App\Provisioning\Data\ProvisioningPlan;
use App\Provisioning\Exceptions\OrderSnapshotIncomplete;

/**
 * Turns a paid order into the request that will build its server.
 *
 * Reads the order's frozen snapshots and nothing else. That restriction is the
 * whole design: a customer paid for a specific machine at a specific price, and
 * every other source of the same facts — the catalog, the current rate, the
 * location's default image — is a statement about today rather than about what
 * was bought. Consulting one would let a catalog edit change what an already-paid
 * customer receives.
 *
 * So there is no PricingService here, no ProductLocationPrice lookup and no
 * re-resolution of a default image. The snapshot recorded which image was
 * chosen and whether the customer named it or took the default; provisioning
 * uses the identity that was resolved then, never the default as it stands now.
 *
 * A snapshot that cannot answer is a fault worth stopping for, not a gap to
 * fill from the catalog.
 */
final readonly class OrderPlanner
{
    /**
     * @throws OrderSnapshotIncomplete
     */
    public function plan(Order $order): ProvisioningPlan
    {
        $cost = $order->cost_snapshot;
        $pricing = $order->pricing_snapshot;
        $image = is_array($pricing['image'] ?? null) ? $pricing['image'] : [];

        return new ProvisioningPlan(
            providerId: $this->int($cost, 'provider_id', $order),
            providerCode: $this->string($cost, 'provider_code', $order),
            providerPlanId: $this->int($cost, 'provider_plan_id', $order),
            // The provider-native identifier, not our row id. This is what
            // crosses the boundary to another company's API.
            providerPlanCode: $this->string($cost, 'provider_plan_code', $order),
            providerLocationId: $this->int($cost, 'provider_location_id', $order),
            providerLocationCode: $this->string($cost, 'provider_location_code', $order),
            providerImageId: $this->int($image, 'provider_image_id', $order, 'image.provider_image_id'),
            providerImageCode: $this->string($image, 'provider_native_id', $order, 'image.provider_native_id'),
            productId: $this->int($pricing, 'product_id', $order),
            productLocationPriceId: $this->int($pricing, 'product_location_price_id', $order),
            planSnapshot: $this->planSnapshot($cost),
            imageSnapshot: $image,
            // Exact decimals as strings, straight from the snapshot. Not parsed
            // into a number and formatted back: that round trip is where scale
            // quietly disappears.
            providerCost: $this->string($cost, 'provider_cost', $order),
            providerCurrency: $this->string($cost, 'provider_currency', $order),
            exchangeRate: $this->string($cost, 'exchange_rate', $order),
            localCostToman: $this->string($cost, 'converted_provider_cost_toman', $order),
            sellingPriceToman: $this->int($pricing, 'selling_price_toman', $order),
            grossMarginToman: $this->string($cost, 'gross_margin_toman', $order),
            billingMode: $this->billingMode($pricing, $order),
            billingCycle: $this->billingCycle($pricing, $order),
        );
    }

    /**
     * A deterministic name for the server this order buys.
     *
     * Derived from the order number, which is itself unique and immutable, so
     * two workers composing a name for one order compose the same one. A
     * counter or a random suffix would give a retry a different name from the
     * attempt it is retrying.
     *
     * Lowercased and stripped to hostname-safe characters, because provider
     * naming rules are narrower than ours and a rejected name is an
     * `invalid_request` that no retry can fix.
     */
    public static function serverName(Order $order): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $order->order_number));
        $slug = trim($slug, '-');

        return 'cbm-'.($slug === '' ? 'order-'.$order->getKey() : $slug);
    }

    /**
     * Labels sent to the provider.
     *
     * Correlation only, and deliberately spare. Labels come back in provider
     * responses, appear in their dashboards and end up in their logs, so
     * nothing about the customer belongs here — no email, no name, no payment
     * detail, and obviously no credential.
     *
     * @return array<string, scalar|null>
     */
    public static function labels(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'provisioning_uuid' => (string) $order->provisioning_uuid,
        ];
    }

    /**
     * What the machine is, as recorded when it was sold.
     *
     * @param  array<string, mixed>  $cost
     * @return array<string, mixed>
     */
    private function planSnapshot(array $cost): array
    {
        return [
            'provider_plan_id' => $cost['provider_plan_id'] ?? null,
            'provider_plan_code' => $cost['provider_plan_code'] ?? null,
            'provider_location_id' => $cost['provider_location_id'] ?? null,
            'provider_location_code' => $cost['provider_location_code'] ?? null,
            'provider_cost' => $cost['provider_cost'] ?? null,
            'provider_currency' => $cost['provider_currency'] ?? null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $source
     *
     * @throws OrderSnapshotIncomplete
     */
    private function string(array $source, string $key, Order $order, ?string $label = null): string
    {
        $value = $source[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw OrderSnapshotIncomplete::missing($order, $label ?? $key);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $source
     *
     * @throws OrderSnapshotIncomplete
     */
    private function int(array $source, string $key, Order $order, ?string $label = null): int
    {
        $value = $source[$key] ?? null;

        // Strict. A snapshot value that is a numeric string is a snapshot
        // written by something that did not know the type, and coercing it
        // would hide that.
        if (! is_int($value)) {
            throw OrderSnapshotIncomplete::missing($order, $label ?? $key);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $pricing
     *
     * @throws OrderSnapshotIncomplete
     */
    private function billingMode(array $pricing, Order $order): BillingMode
    {
        $mode = BillingMode::tryFrom($this->string($pricing, 'billing_mode', $order));

        if (! $mode instanceof BillingMode) {
            throw OrderSnapshotIncomplete::missing($order, 'billing_mode');
        }

        return $mode;
    }

    /**
     * @param  array<array-key, mixed>  $pricing
     *
     * @throws OrderSnapshotIncomplete
     */
    private function billingCycle(array $pricing, Order $order): BillingCycle
    {
        $cycle = BillingCycle::tryFrom($this->string($pricing, 'billing_cycle', $order));

        if (! $cycle instanceof BillingCycle) {
            throw OrderSnapshotIncomplete::missing($order, 'billing_cycle');
        }

        return $cycle;
    }
}
