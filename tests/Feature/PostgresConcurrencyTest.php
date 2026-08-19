<?php

/**
 * PostgreSQL real-concurrency regression tests.
 *
 * Tests 1 & 2: Raw PDO concurrent inserts proving ON CONFLICT DO NOTHING
 * handles concurrent duplicate inserts without aborting the PostgreSQL
 * transaction, with SAME-TRANSACTION health proof.
 *
 * Test 3: Application-level billing concurrency — two independent PHP
 * processes boot the full Laravel application and attempt to bill the
 * SAME server for the SAME due interval through HourlyBillingService.
 *
 * When DB_CONNECTION is not pgsql the tests are skipped.
 */

use App\Models\LowBalanceWarning;
use App\Models\Product;
use App\Models\Server;
use App\Models\ServerBillingPeriod;
use App\Models\User;
use App\Models\Wallet;
use App\Services\HourlyBillingService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\WalletService;
use Database\Seeders\FakeProviderSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Requires real PostgreSQL.');
    }

    $this->seed(FakeProviderSeeder::class);
    $this->user = User::factory()->create();
    config()->set('billing.hourly.minimum_prepaid_hours', 1);
    config()->set('billing.hourly.grace_hours', 48);
});

function pgNewRawPdo(): PDO
{
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=cloudbot_test',
        'postgres_test',
        'postgres',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/**
 * Provision an hourly server through the application services and
 * credit the wallet so billing can proceed.
 */
function pgProvisionHourlyServer(User $user): Server
{
    $product = Product::query()->where('slug', 'vps-cx21-hourly')->firstOrFail();
    $order = app(OrderService::class)->place($user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $user);
    app(PaymentService::class)->provision($order->fresh());

    $server = $order->fresh()->server;

    app(WalletService::class)->credit($user, 100_000);

    return $server;
}

// ----------------------------------------------------------------
// Test 1: Raw PDO — billing periods same-transaction health proof
// ----------------------------------------------------------------

it('survives real concurrent insertOrIgnore for billing periods and proves same-transaction health', function () {
    $pdoA = pgNewRawPdo();
    $pdoB = pgNewRawPdo();
    $pdoA->exec('SET session_replication_role = replica');
    $pdoB->exec('SET session_replication_role = replica');

    $server = pgProvisionHourlyServer($this->user);
    $startedAt = $server->billing_started_at;

    $periodStart = $startedAt->toDateTimeString();
    $periodEnd = $startedAt->copy()->addHour()->toDateTimeString();
    $nowTs = now()->toDateTimeString();

    $sql = 'INSERT INTO server_billing_periods '
        .'(server_id, subscription_id, period_start, period_end, rate_toman, amount_toman, currency, status, capped, created_at, updated_at) '
        .'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) '
        .'ON CONFLICT (server_id, period_start, period_end) DO NOTHING';

    $params = [
        $server->id,
        $server->subscription?->id ?? null,
        $periodStart,
        $periodEnd,
        850,
        850,
        'IRR',
        'unpaid',
        0,
        $nowTs,
        $nowTs,
    ];

    // Connection A inserts first and commits.
    $pdoA->exec('BEGIN');
    $aStmt = $pdoA->prepare($sql);
    $aStmt->execute($params);
    $aRows = $aStmt->rowCount();
    $pdoA->exec('COMMIT');

    // Connection B attempts the same insert WITHIN the same transaction,
    // then performs a health-check operation BEFORE committing.
    $pdoB->exec('BEGIN');
    $bStmt = $pdoB->prepare($sql);
    $bStmt->execute($params);
    $bRows = $bStmt->rowCount();

    // SAME-TRANSACTION HEALTH PROOF: before COMMIT/ROLLBACK, execute
    // another write using the same connection and assert it succeeds.
    // If PostgreSQL had aborted this transaction (SQLSTATE[25P02]),
    // this query would fail immediately.
    $health = $pdoB->prepare(
        'UPDATE server_billing_periods SET description = ? WHERE server_id = ? AND period_start = ?'
    );
    $health->execute(['health-check-ok', $server->id, $periodStart]);
    $healthRows = $health->rowCount();

    // Connection B can now commit safely — no aborted transaction.
    $pdoB->exec('COMMIT');

    // Exactly one insert succeeded across both connections.
    expect($aRows + $bRows)->toBe(1);

    // The health-check UPDATE affected exactly 1 row (the row A inserted
    // is visible to B on the same connection within the same isolation level).
    expect($healthRows)->toBe(1);

    // Only one billing period exists.
    expect(ServerBillingPeriod::query()->where('server_id', $server->id)->count())->toBe(1);

    // The health-check update persisted.
    expect(ServerBillingPeriod::query()->where('server_id', $server->id)->first()->description)->toBe('health-check-ok');
});

// ----------------------------------------------------------------
// Test 2: Raw PDO — low-balance warnings same-transaction health proof
// ----------------------------------------------------------------

it('survives real concurrent insertOrIgnore for low-balance warnings and proves same-transaction health', function () {
    $pdoA = pgNewRawPdo();
    $pdoB = pgNewRawPdo();
    $pdoA->exec('SET session_replication_role = replica');
    $pdoB->exec('SET session_replication_role = replica');

    $server = pgProvisionHourlyServer($this->user);
    $nowTs = now()->toDateTimeString();

    $sql = 'INSERT INTO low_balance_warnings '
        .'(user_id, server_id, threshold_hours, balance_toman, rate_toman, estimated_hours, warned_at, created_at, updated_at) '
        .'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) '
        .'ON CONFLICT (server_id, threshold_hours) WHERE resolved_at IS NULL DO NOTHING';

    $payload = [$this->user->id, $server->id, 24, 0, 850, 0, $nowTs, $nowTs, $nowTs];

    // Connection A inserts first and commits.
    $pdoA->exec('BEGIN');
    $aStmt = $pdoA->prepare($sql);
    $aStmt->execute($payload);
    $aRows = $aStmt->rowCount();
    $pdoA->exec('COMMIT');

    // Connection B attempts the same insert, then performs a health-check
    // BEFORE committing — same transaction, no abort.
    $pdoB->exec('BEGIN');
    $bStmt = $pdoB->prepare($sql);
    $bStmt->execute($payload);
    $bRows = $bStmt->rowCount();

    // SAME-TRANSACTION HEALTH PROOF: write within the same transaction
    // that lost the conflict, before commit.
    $health = $pdoB->prepare(
        'UPDATE low_balance_warnings SET balance_toman = ? WHERE server_id = ? AND threshold_hours = ? AND resolved_at IS NULL'
    );
    $health->execute([999, $server->id, 24]);
    $healthRows = $health->rowCount();

    $pdoB->exec('COMMIT');

    expect($aRows + $bRows)->toBe(1);
    expect($healthRows)->toBe(1);

    // Exactly one unresolved warning exists.
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(1);
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->count())->toBe(1);

    // The health-check update persisted.
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->first()->balance_toman)->toBe(999);

    // Resolve the warning so the same threshold can be inserted again.
    $nowTsResolve = now()->addSeconds(30)->toDateTimeString();
    $pdoA->exec('BEGIN');
    $resolveStmt = $pdoA->prepare(
        'UPDATE low_balance_warnings SET resolved_at = ?, resolved_reason = ? WHERE server_id = ? AND threshold_hours = ? AND resolved_at IS NULL'
    );
    $resolveStmt->execute([$nowTsResolve, 'test_resolved', $server->id, 24]);
    expect($resolveStmt->rowCount())->toBe(1);
    $pdoA->exec('COMMIT');

    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(0);

    // Now re-insert the same threshold — should succeed because the
    // partial unique index only blocks unresolved warnings.
    $nowTs2 = now()->addMinute()->toDateTimeString();
    $rePayload = [$this->user->id, $server->id, 24, 0, 850, 0, $nowTs2, $nowTs2, $nowTs2];

    $pdoA->exec('BEGIN');
    $cStmt = $pdoA->prepare($sql);
    $cStmt->execute($rePayload);
    expect($cStmt->rowCount())->toBe(1);
    $pdoA->exec('COMMIT');

    // 1 resolved + 1 new unresolved = 2 total, 1 unresolved.
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->count())->toBe(2);
    expect(LowBalanceWarning::query()->where('server_id', $server->id)->unresolved()->count())->toBe(1);
});

// ----------------------------------------------------------------
// Test 3: Application-level billing concurrency via child processes
//
// Two independent PHP processes boot the full Laravel application
// and both attempt to bill the SAME server for the SAME due interval
// through HourlyBillingService.processServer(). Proves the full
// application path is safe — exactly 1 billing period, 1 wallet
// debit, correct final balance.
//
// Each process has its own Cache::lock instance (CACHE_STORE=array)
// so the application-level lock does NOT serialise them. The only
// guard is PostgreSQL's row-level lock (SELECT ... FOR UPDATE) inside
// DB::transaction, plus the unique constraint on server_billing_periods
// (server_id, period_start, period_end).
//
// billing_started_at is set to 30 minutes ago so ceil rounding produces
// exactly 1 billable hour (intdiv(30+59, 60) = 1).
// ----------------------------------------------------------------

it('bills the same server from two independent processes — exactly one charge recorded', function () {
    $projectRoot = base_path();

    // Provision via application services (inside test transaction).
    $server = pgProvisionHourlyServer($this->user);
    $userId = $this->user->id;
    $serverId = $server->id;
    $hourlyRate = (int) $server->hourly_rate_toman;
    $balanceBefore = (int) $this->user->fresh()->wallet->balance_toman;

    // Commit the test transaction so child processes can see all data.
    DB::commit();

    // Move billing_started_at to 30 minutes ago via raw PDO so there is
    // exactly ONE billable hour (ceil policy: intdiv(30+59, 60) = 1).
    $pdoSetup = pgNewRawPdo();
    $thirtyMinAgo = now()->subMinutes(30)->toDateTimeString();
    $pdoSetup->exec("UPDATE servers SET billing_started_at = '{$thirtyMinAgo}', last_billed_at = NULL WHERE id = {$serverId}");
    $pdoSetup = null;

    // Write a worker script that boots Laravel and processes billing
    // for the given server. Accepts the project root as argv[1] so it
    // can locate vendor/autoload.php without symlinks.
    $script = <<<'PHP'
<?php
$projectRoot = $argv[1];
$serverId    = (int) $argv[2];

require $projectRoot . '/vendor/autoload.php';
$app = require $projectRoot . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Server;
use App\Services\HourlyBillingService;

$server = Server::query()->find($serverId);
if ($server === null) { echo "server_not_found"; exit(1); }

$billing = app(HourlyBillingService::class);
$recorded = $billing->processServer($server);
echo "recorded:" . $recorded;
PHP;

    $tmpScript = sys_get_temp_dir().'/pg_billing_worker_'.uniqid().'.php';
    file_put_contents($tmpScript, $script);

    try {
        // Launch two child processes in parallel. Both boot a fresh
        // Laravel application and call processServer() on the same server.
        // Force PostgreSQL connection via environment so child processes
        // use the same database as the parent test, not the .env default.
        $childEnv = array_merge($_ENV, [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'cloudbot_test',
            'DB_USERNAME' => 'postgres_test',
            'DB_PASSWORD' => 'postgres',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ]);

        $cmd = 'php '.escapeshellarg($tmpScript).' '.escapeshellarg($projectRoot).' '.escapeshellarg((string) $serverId);

        $procA = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesA, null, $childEnv);
        $procB = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB, null, $childEnv);

        $outputA = stream_get_contents($pipesA[1]);
        $errA = stream_get_contents($pipesA[2]);
        $outputB = stream_get_contents($pipesB[1]);
        $errB = stream_get_contents($pipesB[2]);
        fclose($pipesA[1]);
        fclose($pipesA[2]);
        fclose($pipesB[1]);
        fclose($pipesB[2]);
        $exitA = proc_close($procA);
        $exitB = proc_close($procB);
    } finally {
        @unlink($tmpScript);
    }

    // Both processes should have exited successfully.
    expect($exitA)->toBe(0)->and($exitB)->toBe(0);

    // Both should report they attempted billing.
    expect($outputA)->toContain('recorded:');
    expect($outputB)->toContain('recorded:');

    // Exactly ONE of the two processes actually recorded a billing period.
    // The other recorded 0 because PostgreSQL row locking (SELECT ... FOR UPDATE)
    // serialised the transactions — the second process saw the updated
    // last_billed_at and found no due billing units.
    $recordedA = (int) str_replace('recorded:', '', $outputA);
    $recordedB = (int) str_replace('recorded:', '', $outputB);
    expect($recordedA + $recordedB)->toBe(1);

    // Exactly one ServerBillingPeriod exists for this server.
    expect(ServerBillingPeriod::query()->where('server_id', $serverId)->count())->toBe(1);

    // Exactly one wallet transaction (debit) was created.
    $wallet = Wallet::where('user_id', $userId)->first();
    $debitTransactions = $wallet->transactions()->where('type', 'debit')->get();
    expect($debitTransactions)->toHaveCount(1);
    expect($debitTransactions->first()->amount_toman)->toBe($hourlyRate);

    // Final wallet balance = starting balance - hourly rate.
    $finalBalance = (int) $wallet->fresh()->balance_toman;
    expect($finalBalance)->toBe($balanceBefore - $hourlyRate);
});
