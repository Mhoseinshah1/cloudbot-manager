<?php

declare(strict_types=1);

use App\Models\User;
use App\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->wallet = app(WalletService::class);
});

it('passes when every balance matches its ledger', function (): void {
    $customer = User::factory()->fromTelegram()->create();
    $this->wallet->credit($customer, 100_000, (string) Str::uuid(), 'Top-up');
    $this->wallet->debit($customer->fresh(), 30_000, (string) Str::uuid(), 'Spend');

    $this->artisan('wallet:verify-integrity')->assertExitCode(0);
});

it('counts a customer who has never transacted as zero', function (): void {
    // A left join, so an untouched wallet is checked rather than skipped; a
    // non-zero balance with no ledger is exactly the mismatch worth catching.
    User::factory()->fromTelegram()->count(3)->create();

    $this->artisan('wallet:verify-integrity')->assertExitCode(0);
});

it('detects a balance that was changed behind the ledger', function (): void {
    // Simulates the thing the command exists to find: money written to the
    // balance column without a ledger entry to account for it.
    $customer = User::factory()->fromTelegram()->create();
    $this->wallet->credit($customer, 50_000, (string) Str::uuid(), 'Top-up');

    DB::table('users')->where('id', $customer->id)->update(['wallet_balance_toman' => 500_000]);

    $this->artisan('wallet:verify-integrity')->assertExitCode(1);
});

it('detects a balance that is short of its ledger', function (): void {
    $customer = User::factory()->fromTelegram()->create();
    $this->wallet->credit($customer, 50_000, (string) Str::uuid(), 'Top-up');

    DB::table('users')->where('id', $customer->id)->update(['wallet_balance_toman' => 10_000]);

    $this->artisan('wallet:verify-integrity')->assertExitCode(1);
});

it('never corrects a mismatch it finds', function (): void {
    // Silently moving customer money to make a discrepancy disappear would
    // destroy the evidence of how it arose.
    $customer = User::factory()->fromTelegram()->create();
    $this->wallet->credit($customer, 50_000, (string) Str::uuid(), 'Top-up');
    DB::table('users')->where('id', $customer->id)->update(['wallet_balance_toman' => 999]);

    $this->artisan('wallet:verify-integrity')->assertExitCode(1);

    expect($customer->fresh()->wallet_balance_toman)->toBe(999);
});

it('can check a single customer', function (): void {
    $healthy = User::factory()->fromTelegram()->create();
    $broken = User::factory()->fromTelegram()->create();
    $this->wallet->credit($broken, 50_000, (string) Str::uuid(), 'Top-up');
    DB::table('users')->where('id', $broken->id)->update(['wallet_balance_toman' => 1]);

    $this->artisan('wallet:verify-integrity', ['--user' => $healthy->id])->assertExitCode(0);
    $this->artisan('wallet:verify-integrity', ['--user' => $broken->id])->assertExitCode(1);
});

it('reports ids and amounts without identifying anyone', function (): void {
    $customer = User::factory()->create(['email' => 'someone@example.test', 'name' => 'Real Person']);
    $this->wallet->credit($customer, 50_000, (string) Str::uuid(), 'Top-up');
    DB::table('users')->where('id', $customer->id)->update(['wallet_balance_toman' => 1]);

    $this->artisan('wallet:verify-integrity')
        ->doesntExpectOutputToContain('someone@example.test')
        ->doesntExpectOutputToContain('Real Person')
        ->assertExitCode(1);
});
