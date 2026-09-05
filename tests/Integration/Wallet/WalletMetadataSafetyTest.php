<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Secrets\SecretScrubber;
use App\Wallet\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->wallet = app(WalletService::class);
    $this->customer = User::factory()->fromTelegram()->create();
});

/**
 * Obviously synthetic values. Each must be absent from every stored byte.
 *
 * Assembled at run time rather than written out as literals. These have to be
 * distinctive enough that finding one in a column proves a leak, and a
 * distinctive literal sitting next to a key named `credentials` is precisely
 * what a secret scanner exists to flag — correctly, because nothing in the file
 * tells it the value is fabricated. Building them here keeps the repository
 * free of anything shaped like a credential while leaving the assertions
 * exactly as strong. Memoised so every call within a test sees the same values.
 *
 * @return array<string, string>
 */
function syntheticSecrets(): array
{
    static $values = null;

    if ($values === null) {
        $nonce = bin2hex(random_bytes(6));

        $values = [];

        foreach (['password', 'token', 'api_key', 'authorization', 'credentials', 'recovery_code', 'totp_secret'] as $key) {
            $values[$key] = 'SYNTHETIC-'.strtoupper($key).'-'.$nonce;
        }
    }

    return $values;
}

/** A distinctive marker built the same way, for the one-off cases below. */
function syntheticMarker(string $label): string
{
    return 'SYNTHETIC-'.strtoupper($label).'-'.bin2hex(random_bytes(6));
}

/** The bytes PostgreSQL actually holds, not Eloquent's view of them. */
function rawLedgerRow(WalletTransaction $transaction): object
{
    $row = DB::table('wallet_transactions')
        ->select('metadata', 'description')
        ->where('id', $transaction->getKey())
        ->first();

    expect($row)->not->toBeNull();

    return (object) $row;
}

it('never writes a secret-bearing value into the raw ledger metadata column', function (): void {
    // The ledger is immutable and kept for years. A credential written here
    // could never be taken back out again.
    $secrets = syntheticSecrets();

    $transaction = $this->wallet->credit(
        $this->customer, 500_000, (string) Str::uuid(), 'Wallet top-up',
        metadata: $secrets,
    );

    $raw = rawLedgerRow($transaction);

    foreach ($secrets as $key => $value) {
        expect($raw->metadata)->not->toContain($value, "{$key} leaked into wallet_transactions.metadata");
    }

    // The key names survive, so an investigator can still see what was offered.
    $decoded = json_decode((string) $raw->metadata, true);

    foreach (array_keys($secrets) as $key) {
        expect($decoded[$key])->toBe(SecretScrubber::REDACTED);
    }
});

it('scrubs secrets nested inside ledger metadata', function (): void {
    $marker = syntheticMarker('nested');

    $transaction = $this->wallet->credit(
        $this->customer, 100_000, (string) Str::uuid(), 'Wallet top-up',
        metadata: ['gateway' => ['callback' => ['api_key' => $marker]]],
    );

    expect(rawLedgerRow($transaction)->metadata)->not->toContain($marker);
});

it('redacts a payload nested deeper than the scrubber inspects', function (): void {
    // What has not been inspected cannot be shown to be free of credentials,
    // so anything that deep is redacted rather than passed through. A payload
    // shaped like that is a wholesale provider response, not the handful of
    // named facts the ledger is for.
    $marker = syntheticMarker('deep');
    $deep = ['password' => $marker];

    for ($i = 0; $i < 20; $i++) {
        $deep = ['nested' => $deep];
    }

    $transaction = $this->wallet->credit(
        $this->customer, 100_000, (string) Str::uuid(), 'Wallet top-up',
        metadata: $deep,
    );

    expect(rawLedgerRow($transaction)->metadata)->not->toContain($marker);
});

it('never writes a secret-bearing value into the raw description column', function (): void {
    $marker = syntheticMarker('bearer');

    $transaction = $this->wallet->credit(
        $this->customer, 100_000, (string) Str::uuid(),
        "Top-up authorised with Bearer {$marker}",
    );

    $raw = rawLedgerRow($transaction);

    expect($raw->description)->not->toContain($marker)
        ->and($raw->description)->toContain('Top-up authorised with')
        ->and($raw->description)->toContain(SecretScrubber::REDACTED);
});

it('keeps secrets out of the audit entry for the movement', function (): void {
    $secrets = syntheticSecrets();
    $marker = syntheticMarker('audit');

    $this->wallet->credit(
        $this->customer, 250_000, (string) Str::uuid(),
        "Top-up via Bearer {$marker}",
        metadata: $secrets,
    );

    $rows = DB::table('audit_logs')
        ->where('event', AuditEvent::WalletCredit)
        ->get(['before', 'after', 'metadata']);

    expect($rows)->toHaveCount(1);

    $serialised = json_encode($rows);

    foreach (array_values($secrets) as $value) {
        expect($serialised)->not->toContain($value);
    }

    expect($serialised)->not->toContain($marker);
});

it('keeps secrets out of the log stream', function (): void {
    $written = [];

    Log::listen(function ($message) use (&$written): void {
        $written[] = $message->message.' '.json_encode($message->context);
    });

    $this->wallet->credit(
        $this->customer, 100_000, (string) Str::uuid(), 'Wallet top-up',
        metadata: syntheticSecrets(),
    );

    foreach (array_values(syntheticSecrets()) as $value) {
        expect(implode("\n", $written))->not->toContain($value);
    }
});

it('leaves ordinary operational metadata readable', function (): void {
    // Redaction must not cost the ledger its forensic value.
    $transaction = $this->wallet->credit(
        $this->customer, 640_000, (string) Str::uuid(), 'Wallet top-up',
        metadata: [
            'bank_reference' => 'BANK-2026-000123',
            'gateway' => 'manual',
            'verified_by' => 'finance-desk',
            'amount_toman' => 640_000,
            'attempt' => 2,
        ],
    );

    $stored = $transaction->fresh()->metadata;

    expect($stored['bank_reference'])->toBe('BANK-2026-000123')
        ->and($stored['gateway'])->toBe('manual')
        ->and($stored['verified_by'])->toBe('finance-desk')
        ->and($stored['amount_toman'])->toBe(640_000)
        ->and($stored['attempt'])->toBe(2)
        ->and($transaction->fresh()->description)->toBe('Wallet top-up');
});

it('scrubs an administrative adjustment reason and metadata', function (): void {
    $actor = User::factory()->create();
    $actor->assignRole(AdminRole::Owner->value);

    $this->wallet->credit($this->customer, 100_000, (string) Str::uuid(), 'Wallet top-up');

    $reasonMarker = syntheticMarker('adjust-reason');
    $metadataMarker = syntheticMarker('adjust-metadata');

    $transaction = $this->wallet->adjust(
        $this->customer, -50_000, (string) Str::uuid(),
        "Corrected after Bearer {$reasonMarker} was replayed",
        $actor,
        metadata: ['token' => $metadataMarker],
    );

    $raw = rawLedgerRow($transaction);

    expect($raw->description)->not->toContain($reasonMarker)
        ->and($raw->description)->toContain('Corrected after')
        ->and($raw->metadata)->not->toContain($metadataMarker);
});

it('keeps the ledger immutable after sanitization', function (): void {
    $transaction = $this->wallet->credit(
        $this->customer, 100_000, (string) Str::uuid(), 'Wallet top-up',
        metadata: syntheticSecrets(),
    );

    // In a savepoint: the rejection aborts its own transaction, and without
    // this the assertions below could not run.
    expect(fn () => DB::transaction(fn () => DB::table('wallet_transactions')
        ->where('id', $transaction->getKey())
        ->update(['metadata' => json_encode(syntheticSecrets())])))
        ->toThrow(Illuminate\Database\QueryException::class);

    expect(rawLedgerRow($transaction->fresh())->metadata)
        ->not->toContain(syntheticSecrets()['password']);
});

it('replays a sanitized movement without writing a second one', function (): void {
    // Sanitization happens before the idempotency lookup, so a replay must
    // still resolve to the row that exists.
    $key = (string) Str::uuid();

    $first = $this->wallet->credit(
        $this->customer, 300_000, $key, 'Wallet top-up', metadata: syntheticSecrets(),
    );
    $second = $this->wallet->credit(
        $this->customer, 300_000, $key, 'Wallet top-up', metadata: syntheticSecrets(),
    );

    expect($second->getKey())->toBe($first->getKey())
        ->and(WalletTransaction::query()->count())->toBe(1)
        ->and($this->customer->fresh()->wallet_balance_toman)->toBe(300_000)
        ->and(AuditLog::query()->where('event', AuditEvent::WalletCredit)->count())->toBe(1);
});
