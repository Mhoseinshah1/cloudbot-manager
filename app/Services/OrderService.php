<?php

namespace App\Services;

use App\Exceptions\ProviderException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderPlan;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class OrderService
{
    public function __construct(
        private PricingService $pricing,
        private AuditService $audit,
    ) {}

    public function place(User $user, Product $product, ?string $couponCode = null): Order
    {
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

        $coupon = $couponCode !== null
            ? $this->resolveCoupon($couponCode, $product)
            : null;

        $price = $this->pricing->compute($plan, $product);

        $discount = $coupon?->discountFor($price['selling_price']) ?? 0;
        $total = $price['selling_price'] - $discount;

        $order = Order::query()->create([
            'order_number' => 'ORD-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6)),
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
            'total_toman' => $total,
            'discount_toman' => $discount,
            'coupon_id' => $coupon?->id,
            'cost_snapshot' => $price,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'provider_plan_id' => $plan->id,
            'name' => $product->name,
            'quantity' => 1,
            'unit_price_toman' => $price['selling_price'],
            'line_total_toman' => $price['selling_price'],
        ]);

        if ($coupon !== null) {
            $coupon->increment('used_count');
        }

        $this->audit->record('order.created', $order, $user, after: [
            'order_number' => $order->order_number,
            'total_toman' => $order->total_toman,
            'product' => $product->slug,
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

    private function resolveCoupon(string $code, Product $product): Coupon
    {
        $coupon = Coupon::query()->where('code', $code)->first();

        if ($coupon === null || ! $coupon->isValid($product->price_toman ?? null)) {
            throw new RuntimeException("Coupon [{$code}] is not valid.");
        }

        return $coupon;
    }
}
