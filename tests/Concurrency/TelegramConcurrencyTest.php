<?php

declare(strict_types=1);

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramAccount;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Telegram\TelegramUpdateNormalizer;
use App\Telegram\TelegramUpdateRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Concurrency\ForkedWorkers;

/**
 * Two deliveries at the same instant, in genuinely separate processes.
 *
 * Telegram retries, and it does not wait politely for the first attempt to
 * finish. The races here are the real ones: the same update arriving twice at
 * once, and a customer's very first two messages arriving together. Both are
 * settled by a unique index rather than by a check in PHP, and only real
 * concurrency can show that.
 */
function resetTelegramTables(): void
{
    DB::statement('TRUNCATE telegram_updates, telegram_accounts RESTART IDENTITY CASCADE');
    DB::table('users')->delete();
}

beforeEach(function (): void {
    resetTelegramTables();
});

afterEach(function (): void {
    resetTelegramTables();
});

function payloadFor(int $updateId, int $telegramUserId): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => $telegramUserId, 'is_bot' => false, 'first_name' => 'رضا'],
            'chat' => ['id' => $telegramUserId, 'type' => 'private'],
            'text' => '/start',
        ],
    ];
}

it('records one row when the same update arrives six times at once', function (): void {
    $payload = payloadFor(880100, 5_500_123_456);

    $results = ForkedWorkers::run(6, function () use ($payload): array {
        $normalized = app(TelegramUpdateNormalizer::class)->normalize($payload);
        $recorded = app(TelegramUpdateRecorder::class)->record($normalized);

        return ['isNew' => $recorded['isNew'], 'id' => $recorded['update']->getKey()];
    });

    $winners = array_filter($results, static fn (array $r): bool => ($r['isNew'] ?? false) === true);
    $ids = array_values(array_unique(array_column($results, 'id')));

    expect(TelegramUpdate::query()->count())->toBe(1)
        // Exactly one insert won; the rest read the winner's row.
        ->and($winners)->toHaveCount(1)
        // And every worker agreed which row it was.
        ->and($ids)->toHaveCount(1)
        ->and(array_filter(array_column($results, 'error')))->toBe([]);
});

it('creates one customer when their first two messages arrive together', function (): void {
    // Different update ids — so deduplication cannot help — from one person, at
    // one instant. The unique index on telegram_user_id is the only arbiter.
    $telegramUserId = 5_500_123_456;

    $results = ForkedWorkers::run(6, function (int $index) use ($telegramUserId): array {
        $payload = payloadFor(880200 + $index, $telegramUserId);
        $normalized = app(TelegramUpdateNormalizer::class)->normalize($payload);
        app(TelegramUpdateRecorder::class)->record($normalized);

        try {
            $account = app(App\Telegram\TelegramAccountService::class)->identify($normalized);

            return ['account' => $account?->getKey(), 'user' => $account?->user_id];
        } catch (Throwable $exception) {
            return ['error' => $exception::class.': '.$exception->getMessage()];
        }
    });

    expect(TelegramAccount::query()->count())->toBe(1)
        // No orphan customer left behind by a transaction that lost the race.
        ->and(User::query()->count())->toBe(1)
        ->and(TelegramUpdate::query()->count())->toBe(6)
        ->and(array_filter(array_column($results, 'error')))->toBe([]);

    $accounts = array_values(array_unique(array_filter(array_column($results, 'account'))));
    $users = array_values(array_unique(array_filter(array_column($results, 'user'))));

    // Every worker ended up using the same identity.
    expect($accounts)->toHaveCount(1)
        ->and($users)->toHaveCount(1);
});

it('keeps two different people apart under contention', function (): void {
    $results = ForkedWorkers::run(6, function (int $index): array {
        // Three workers for each of two customers, all at once.
        $telegramUserId = $index % 2 === 0 ? 5_500_111_111 : 6_600_222_222;

        $payload = payloadFor(880300 + $index, $telegramUserId);
        $normalized = app(TelegramUpdateNormalizer::class)->normalize($payload);
        app(TelegramUpdateRecorder::class)->record($normalized);

        $account = app(App\Telegram\TelegramAccountService::class)->identify($normalized);

        return ['telegram_user_id' => $account?->telegram_user_id, 'user' => $account?->user_id];
    });

    expect(TelegramAccount::query()->count())->toBe(2)
        ->and(User::query()->count())->toBe(2)
        ->and(array_filter(array_column($results, 'error')))->toBe([]);

    // Two identities, two customers, and nobody merged into anybody.
    $byIdentity = [];

    foreach ($results as $result) {
        $byIdentity[(string) $result['telegram_user_id']][] = $result['user'];
    }

    foreach ($byIdentity as $userIds) {
        expect(array_unique($userIds))->toHaveCount(1);
    }

    expect($byIdentity)->toHaveCount(2);
});

it('lets exactly one worker mark an update processed', function (): void {
    $payload = payloadFor(880400, 5_500_123_456);
    $normalized = app(TelegramUpdateNormalizer::class)->normalize($payload);
    $update = app(TelegramUpdateRecorder::class)->record($normalized)['update'];
    $updateId = (int) $update->getKey();

    $results = ForkedWorkers::run(6, function () use ($updateId): array {
        $update = TelegramUpdate::query()->findOrFail($updateId);

        return ['won' => app(TelegramUpdateRecorder::class)->markProcessed($update)];
    });

    $winners = array_filter(array_column($results, 'won'));

    // Compare-and-set on the status: two workers that both handled the update
    // cannot both believe they were the one that finished it.
    expect($winners)->toHaveCount(1)
        ->and(TelegramUpdate::query()->findOrFail($updateId)->processed_at)->not->toBeNull();
});

it('lets exactly one worker enter processing, not merely finish it', function (): void {
    // The markProcessed race above proves only that one worker wins the last
    // write. That is not the guarantee that matters: a Telegram message is
    // already delivered by the time the compare-and-set runs, so what has to be
    // true is that only one worker ever reaches the processor at all.
    //
    // Every child fakes its own HTTP, so what each one reports is the number of
    // Telegram calls that process actually made — a probe at the processing
    // boundary rather than at its conclusion.
    config()->set('telegram.api_base_url', 'https://api.telegram.test');
    config()->set('telegram.bot_token', '11'.random_int(1_000_000, 9_999_999).':AA'.bin2hex(random_bytes(12)));

    $payload = payloadFor(880500, 5_500_123_456);
    $normalized = app(TelegramUpdateNormalizer::class)->normalize($payload);
    $update = app(TelegramUpdateRecorder::class)->record($normalized)['update'];
    $updateId = (int) $update->getKey();

    $results = ForkedWorkers::run(6, function () use ($updateId): array {
        Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Http::preventStrayRequests();

        app()->call([new ProcessTelegramUpdateJob($updateId), 'handle']);

        return ['sent' => Http::recorded()->count()];
    });

    $sends = array_map(static fn (array $r): int => (int) ($r['sent'] ?? 0), $results);
    $entered = array_filter($sends);

    expect(array_filter(array_column($results, 'error')))->toBe([])
        // One process greeted the customer. The other five reached the lock,
        // found it taken, and did nothing at all.
        ->and(array_sum($sends))->toBe(1)
        ->and($entered)->toHaveCount(1)
        ->and(User::query()->count())->toBe(1)
        ->and(TelegramAccount::query()->count())->toBe(1)
        ->and(TelegramUpdate::query()->findOrFail($updateId)->status->value)->toBe('processed');
});

it('does not process again once an earlier worker has finished', function (): void {
    // The sequential shape: the lock is free, because whoever held it is done.
    // A worker arriving now must decide from what that worker left behind.
    $payload = payloadFor(880600, 6_600_222_222);
    $normalized = app(TelegramUpdateNormalizer::class)->normalize($payload);
    $update = app(TelegramUpdateRecorder::class)->record($normalized)['update'];
    $updateId = (int) $update->getKey();

    app(TelegramUpdateRecorder::class)->markProcessed($update);

    config()->set('telegram.api_base_url', 'https://api.telegram.test');
    config()->set('telegram.bot_token', '11'.random_int(1_000_000, 9_999_999).':AA'.bin2hex(random_bytes(12)));

    $results = ForkedWorkers::run(6, function () use ($updateId): array {
        Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Http::preventStrayRequests();

        app()->call([new ProcessTelegramUpdateJob($updateId), 'handle']);

        return ['sent' => Http::recorded()->count()];
    });

    $sends = array_map(static fn (array $r): int => (int) ($r['sent'] ?? 0), $results);

    // Six workers, an uncontended lock each, and not one Telegram call between
    // them.
    expect(array_filter(array_column($results, 'error')))->toBe([])
        ->and(array_sum($sends))->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(TelegramAccount::query()->count())->toBe(0);
});
