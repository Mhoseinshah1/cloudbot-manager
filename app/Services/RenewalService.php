<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\User;
use RuntimeException;

/**
 * Handles monthly VPS renewal through the standard financial flow:
 *
 *   create renewal order → invoice → payment → extend service expiration
 *
 * Never mutates expires_at before successful payment.
 * Idempotent: duplicate renewal attempts for an already-paid order are safe.
 */
class RenewalService
{
    public function __construct(
        private OrderService $orders,
        private PaymentService $payments,
        private AuditService $audit,
    ) {}

    /**
     * Create a renewal order for a monthly server.
     *
     * Returns the created Order. Caller must then create invoice + payment.
     */
    public function createRenewalOrder(Server $server, ?User $actor = null): Order
    {
        if ($server->billing_mode !== 'monthly') {
            throw new RuntimeException('Only monthly servers can be renewed through this flow.');
        }

        if ($server->expires_at === null) {
            throw new RuntimeException('Server has no expiration date; cannot renew.');
        }

        if ($server->user_id === null) {
            throw new RuntimeException('Server has no owner; cannot renew.');
        }

        /** @var Product|null $product */
        $product = $server->product;

        if ($product === null) {
            throw new RuntimeException('Server has no associated product; cannot compute renewal price.');
        }

        $order = $this->orders->place(
            $product->user ?? $actor ?? User::findOrFail($server->user_id),
            $product,
        );

        $this->audit->record('renewal.order_created', $server, $actor, after: [
            'order_number' => $order->order_number,
            'renewal_amount' => $order->total_toman,
            'current_expiry' => $server->expires_at->toIso8601String(),
        ]);

        return $order;
    }

    /**
     * Extend the server's expiration after a successful renewal payment.
     *
     * This is the ONLY place where expires_at is mutated for renewals.
     * It must only be called after payment is confirmed.
     */
    public function extendExpiration(Server $server, ?User $actor = null): void
    {
        if ($server->expires_at === null) {
            throw new RuntimeException('Server has no expiration date; cannot extend.');
        }

        $newExpiry = $server->expires_at->copy()->addMonth();

        $server->update(['expires_at' => $newExpiry]);

        $this->audit->record('renewal.extended', $server, $actor, after: [
            'old_expiry' => $server->getOriginal('expires_at'),
            'new_expiry' => $newExpiry->toIso8601String(),
        ]);
    }

    /**
     * Complete the full renewal: create order → invoice → payment → confirm → extend.
     *
     * For development/testing: uses ManualGateway and extends immediately.
     */
    public function processRenewal(Server $server, ?User $actor = null): array
    {
        $order = $this->createRenewalOrder($server, $actor);
        $invoice = $this->orders->createInvoice($order, 'manual');
        $payment = $this->payments->start($invoice, 'manual');
        $this->payments->confirm($payment, ['approved' => true], $actor);
        $this->extendExpiration($server->fresh() ?? $server, $actor);

        return [
            'order' => $order,
            'invoice' => $invoice,
            'payment' => $payment,
            'new_expiry' => $server->fresh()?->expires_at,
        ];
    }
}
