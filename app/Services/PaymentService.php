<?php

namespace App\Services;

use App\Enums\BillingMode;
use App\Jobs\ProvisionServerJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private PaymentManager $manager,
        private AuditService $audit,
        private WalletService $wallets,
    ) {}

    public function start(Invoice $invoice, string $gatewayCode = 'manual'): Payment
    {
        $payment = Payment::query()->create([
            'payment_uuid' => (string) Str::uuid(),
            'invoice_id' => $invoice->id,
            'order_id' => $invoice->order_id,
            'user_id' => $invoice->user_id,
            'gateway_code' => $gatewayCode,
            'amount_toman' => $invoice->amount_toman,
            'status' => Payment::STATUS_PENDING,
        ]);

        $gateway = $this->manager->resolve($gatewayCode);

        $payment->update([
            'metadata' => $gateway->requestPayment($payment),
        ]);

        return $payment;
    }

    /**
     * Confirms a payment. Idempotent and protected by a distributed lock so
     * duplicate webhook/callback deliveries cannot double-confirm.
     *
     * @param  array<string, mixed>  $data
     */
    public function confirm(Payment $payment, array $data = [], ?User $actor = null): Payment
    {
        $lock = Cache::lock('payment-confirm:'.$payment->id, 30);

        if (! $lock->get()) {
            throw new RuntimeException('Payment confirmation is already in progress.');
        }

        try {
            return DB::transaction(function () use ($payment, $data, $actor) {
                $payment->refresh();

                // Idempotency guard: only confirm a pending payment once.
                if ($payment->status !== Payment::STATUS_PENDING) {
                    return $payment;
                }

                $gateway = $this->manager->resolve($payment->gateway_code);

                if (! $gateway->verifyPayment($payment, $data)) {
                    $payment->update(['status' => Payment::STATUS_FAILED]);

                    $this->audit->record('payment.rejected', $payment, $actor, after: [
                        'payment_uuid' => $payment->payment_uuid,
                        'amount_toman' => $payment->amount_toman,
                    ]);

                    return $payment;
                }

                $payment->update([
                    'status' => Payment::STATUS_PAID,
                    'gateway_transaction_id' => $data['transaction_id'] ?? ('manual-'.Str::random(12)),
                    'verified_at' => now(),
                ]);

                $payment->invoice?->update([
                    'status' => Invoice::STATUS_PAID,
                    'paid_at' => now(),
                ]);

                $payment->order?->update([
                    'status' => Order::STATUS_PAID,
                    'gateway_code' => $payment->gateway_code,
                    'paid_at' => now(),
                ]);

                // Hourly VPS orders and explicit wallet top-up orders fund
                // the customer wallet. The payment row is the idempotent
                // financial authority; duplicate confirmations never credit
                // the wallet twice because non-pending payments return above.
                $order = $payment->order;
                /** @var User|null $user */
                $user = $payment->user;

                $fundsWallet = $order !== null && (
                    $order->isWalletTopUp()
                    || (BillingMode::tryFrom((string) $order->billing_mode)?->isHourly() ?? false)
                );

                if ($fundsWallet && $user !== null) {
                    $description = $order->isWalletTopUp()
                        ? 'Wallet top-up payment '.$order->order_number
                        : 'Payment for order '.$order->order_number;

                    $this->wallets->credit(
                        $user,
                        $payment->amount_toman,
                        $description,
                        $payment,
                    );
                }

                $this->audit->record('payment.confirmed', $payment, $actor, before: [
                    'status' => Payment::STATUS_PENDING,
                ], after: [
                    'payment_uuid' => $payment->payment_uuid,
                    'amount_toman' => $payment->amount_toman,
                    'order_number' => $payment->order?->order_number,
                ]);

                return $payment;
            });
        } finally {
            $lock->release();
        }
    }

    public function provision(Order $order): void
    {
        if ($order->isWalletTopUp()) {
            throw new RuntimeException('Wallet top-up orders cannot be provisioned.');
        }

        if ($order->status !== Order::STATUS_PAID) {
            throw new RuntimeException('Only paid orders can be provisioned.');
        }

        ProvisionServerJob::dispatch($order);
    }
}
