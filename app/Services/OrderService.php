<?php

namespace App\Services;

use App\Exceptions\ProviderException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\ProviderPlanPrice;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class OrderService
{
    public function __construct(
        private PricingService $pricing,
        private AuditService $audit,
        private HourlyBillingService $billing,
        private ProductBillingValidator $validator,
    ) {}

    public function place(
        User $user,
        Product $product,
        ?string $couponCode = null,
        ?int $selectedLocationId = null,
        ?int $selectedImageId = null,
    ): Order {
        // Domain-level billing validation — invalid products are rejected
        // even when the Filament forms are bypassed.
        $this->validator->validate($product);

        if ($product->status !== Product::STATUS_ACTIVE || ! $product->enabled) {
            throw new RuntimeException("Product [{$product->slug}] is not available.");
        }

        $provider = $product->provider;

        if ($provider === null) {
            throw ProviderException::unavailable('Provider', 'unknown');
        }

        if (! $provider->enabled) {
            throw ProviderException::unavailable('Provider', $provider->code);
        }

        $plan = $product->providerPlan;

        if (! $plan instanceof ProviderPlan || ! $plan->enabled) {
            throw ProviderException::unavailable('Plan', $product->provider_plan_id ?? 'none');
        }

        $this->validateProvisioningSelection($provider->id, $plan, $selectedLocationId, $selectedImageId);

        $price = $this->pricing->compute($plan, $product);

        // For hourly / hourly_capped products the initial order is wallet
        // funding: enough prepaid balance to satisfy the configured minimum
        // (minus any existing balance), never less than the first hourly
        // unit. Recurring usage is settled from the wallet by the hourly
        // billing engine only — the initial payment is never a usage charge.
        $orderTotal = $this->pricing->orderTotalToman($price, $product);

        if ($product->billingMode()->isHourly()) {
            $orderTotal = $this->billing->fundingAmount($user, (int) $price['hourly_price']);
        }

        $coupon = $couponCode !== null
            ? $this->resolveCoupon($couponCode, $orderTotal)
            : null;

        $discount = $coupon?->discountFor($orderTotal) ?? 0;
        $total = $orderTotal - $discount;

        $order = Order::query()->create([
            'order_number' => 'ORD-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6)),
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
            'billing_mode' => $product->billingMode()->value,
            'total_toman' => $total,
            'discount_toman' => $discount,
            'coupon_id' => $coupon?->id,
            'cost_snapshot' => $price,
            'selected_location_id' => $selectedLocationId,
            'selected_image_id' => $selectedImageId,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'provider_plan_id' => $plan->id,
            'name' => $product->name,
            'quantity' => 1,
            'unit_price_toman' => $orderTotal,
            'line_total_toman' => $orderTotal,
        ]);

        if ($coupon !== null) {
            $coupon->increment('used_count');
        }

        $this->audit->record('order.created', $order, $user, after: [
            'order_number' => $order->order_number,
            'total_toman' => $order->total_toman,
            'product' => $product->slug,
            'selected_location_id' => $selectedLocationId,
            'selected_image_id' => $selectedImageId,
        ]);

        return $order;
    }

    public function createTopUpOrder(User $user, int $amountToman): Order
    {
        if ($amountToman <= 0) {
            throw new RuntimeException('Top-up amount must be positive.');
        }

        $order = Order::query()->create([
            'order_number' => 'TOPUP-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6)),
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
            'billing_mode' => 'monthly',
            'total_toman' => $amountToman,
            'discount_toman' => 0,
            'cost_snapshot' => [
                'purpose' => Order::PURPOSE_WALLET_TOPUP,
                'amount_toman' => $amountToman,
            ],
        ]);

        $this->audit->record('wallet.topup_order.created', $order, $user, after: [
            'order_number' => $order->order_number,
            'amount_toman' => $amountToman,
        ]);

        return $order;
    }

    public function createInvoice(Order $order, string $gatewayCode = 'manual'): Invoice
    {
        return Invoice::query()->create([
            'invoice_number' => 'INV-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6)),
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'status' => Invoice::STATUS_PENDING,
            'amount_toman' => $order->total_toman,
            'gateway_code' => $gatewayCode,
        ]);
    }

    private function validateProvisioningSelection(
        int $providerId,
        ProviderPlan $plan,
        ?int $selectedLocationId,
        ?int $selectedImageId,
    ): void {
        if ($selectedLocationId !== null) {
            $location = ProviderLocation::query()
                ->whereKey($selectedLocationId)
                ->where('provider_id', $providerId)
                ->where('enabled', true)
                ->first();

            if ($location === null) {
                throw ProviderException::unavailable('Location', (string) $selectedLocationId);
            }

            $available = ProviderPlanPrice::query()
                ->where('provider_plan_id', $plan->id)
                ->where('provider_location_id', $location->id)
                ->where('deprecated', false)
                ->exists();

            if (! $available) {
                throw ProviderException::unavailable('Plan/location', $plan->provider_plan_id.'@'.$location->provider_location_id);
            }
        }

        if ($selectedImageId !== null) {
            $image = ProviderImage::query()
                ->whereKey($selectedImageId)
                ->where('provider_id', $providerId)
                ->where('enabled', true)
                ->whereNull('deprecated')
                ->first();

            if ($image === null) {
                throw ProviderException::unavailable('Image', (string) $selectedImageId);
            }

            if ($plan->architecture !== null && $image->architecture !== null && $plan->architecture !== $image->architecture) {
                throw ProviderException::unavailable('Image architecture', $image->architecture);
            }
        }
    }

    private function resolveCoupon(string $code, int $orderTotal): Coupon
    {
        $coupon = Coupon::query()->where('code', $code)->first();

        if ($coupon === null || ! $coupon->isValid($orderTotal)) {
            throw new RuntimeException("Coupon [{$code}] is not valid.");
        }

        return $coupon;
    }
}
