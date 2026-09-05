<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Billing\Exceptions\PaymentNotVerifiable;
use App\Billing\Gateways\ManualGateway;
use App\Billing\PaymentService;
use App\Enums\AdminRole;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ForkedWorkers;

/**
 * Two operators clicking "verify" at the same moment.
 *
 * The realistic version of a duplicate settlement, and the one a sequential
 * replay test cannot produce: both processes read a pending payment before
 * either has written anything.
 */
beforeEach(function (): void {
    DB::statement('TRUNCATE wallet_transactions, invoices, payments, audit_logs RESTART IDENTITY CASCADE');
    DB::table('users')->delete();

    app(RoleProvisioner::class)->sync();

    $this->customer = User::factory()->fromTelegram()->create();
    $this->finance = User::factory()->create();
    $this->finance->assignRole(AdminRole::Finance->value);
});

afterEach(function (): void {
    DB::statement('TRUNCATE wallet_transactions, invoices, payments, audit_logs RESTART IDENTITY CASCADE');
    DB::table('users')->delete();
});

it('settles a payment exactly once when verified concurrently', function (): void {
    $payment = app(PaymentService::class)->createPayment(
        $this->customer, app(ManualGateway::class), 400_000, (string) Str::uuid(),
    );

    $paymentId = $payment->id;
    $financeId = $this->finance->id;

    $results = ForkedWorkers::run(4, function () use ($paymentId, $financeId): array {
        $verifier = User::query()->findOrFail($financeId);
        $payment = Payment::query()->findOrFail($paymentId);

        try {
            app(PaymentService::class)->verify(
                $payment, app(ManualGateway::class), $verifier, ['reference' => 'BANK-CONCURRENT'],
            );

            return ['ok' => true, 'outcome' => 'settled'];
        } catch (PaymentNotVerifiable $exception) {
            return ['ok' => true, 'outcome' => 'refused'];
        }
    });

    foreach ($results as $result) {
        expect($result['error'])->toBeNull();
    }

    // One credit, one invoice, one settled payment — whatever the interleaving.
    expect((int) DB::table('users')->where('id', $this->customer->id)->value('wallet_balance_toman'))->toBe(400_000)
        ->and(DB::table('wallet_transactions')->count())->toBe(1)
        ->and(DB::table('invoices')->count())->toBe(1)
        ->and(DB::table('payments')->where('status', 'paid')->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('event', 'payment.verified')->count())->toBe(1);
});

it('lets one bank reference settle only one of two concurrent payments', function (): void {
    // The same real transfer offered against two payments at once. The unique
    // index is the only thing that can decide this: both processes look before
    // either writes.
    $service = app(PaymentService::class);
    $gateway = app(ManualGateway::class);

    $first = $service->createPayment($this->customer, $gateway, 150_000, (string) Str::uuid())->id;
    $second = $service->createPayment($this->customer, $gateway, 150_000, (string) Str::uuid())->id;
    $financeId = $this->finance->id;

    $results = ForkedWorkers::run(2, function (int $index) use ($first, $second, $financeId): array {
        $verifier = User::query()->findOrFail($financeId);
        $payment = Payment::query()->findOrFail($index === 0 ? $first : $second);

        try {
            app(PaymentService::class)->verify(
                $payment, app(ManualGateway::class), $verifier, ['reference' => 'BANK-ONE-TRANSFER'],
            );

            return ['ok' => true, 'outcome' => 'settled'];
        } catch (PaymentNotVerifiable) {
            return ['ok' => true, 'outcome' => 'refused'];
        }
    });

    $outcomes = array_column($results, 'outcome');

    expect(array_count_values($outcomes)['settled'] ?? 0)->toBe(1)
        // Only one wallet credit, for one transfer.
        ->and((int) DB::table('users')->where('id', $this->customer->id)->value('wallet_balance_toman'))->toBe(150_000)
        ->and(DB::table('wallet_transactions')->count())->toBe(1)
        ->and(DB::table('invoices')->count())->toBe(1);
});

it('keeps the wallet correct when settlement and a debit collide', function (): void {
    // A settlement crediting the wallet while the customer spends. Both lock
    // the user row, in the same order, so they queue rather than deadlock.
    $service = app(PaymentService::class);
    app(App\Wallet\WalletService::class)->credit(
        $this->customer, 100_000, (string) Str::uuid(), 'Seed',
    );

    $paymentId = $service->createPayment(
        $this->customer, app(ManualGateway::class), 200_000, (string) Str::uuid(),
    )->id;

    $customerId = $this->customer->id;
    $financeId = $this->finance->id;

    ForkedWorkers::run(2, function (int $index) use ($paymentId, $customerId, $financeId): array {
        if ($index === 0) {
            app(PaymentService::class)->verify(
                Payment::query()->findOrFail($paymentId),
                app(ManualGateway::class),
                User::query()->findOrFail($financeId),
                ['reference' => 'BANK-COLLIDE'],
            );

            return ['ok' => true];
        }

        try {
            app(App\Wallet\WalletService::class)->debit(
                User::query()->findOrFail($customerId), 60_000, 'collide-debit', 'Concurrent spend',
            );
        } catch (App\Wallet\Exceptions\InsufficientBalance) {
            // Legitimate if it ran before the credit landed.
        }

        return ['ok' => true];
    });

    $balance = (int) DB::table('users')->where('id', $customerId)->value('wallet_balance_toman');
    $ledger = (int) DB::table('wallet_transactions')->where('user_id', $customerId)->sum('amount_toman');

    expect($balance)->toBe($ledger)
        ->and($balance)->toBeGreaterThanOrEqual(0);

    $this->artisan('wallet:verify-integrity')->assertExitCode(0);
});
