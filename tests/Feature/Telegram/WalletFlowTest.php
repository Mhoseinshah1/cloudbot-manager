<?php

use App\Models\Order;
use App\Models\Payment;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\Flows\WalletFlow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('telegram.bot_token', 'test-bot-token');
    config()->set('telegram.api_base_url', 'https://api.telegram.org');
    config()->set('telegram.state_store', 'array');
    config()->set('telegram.topup_gateway', 'manual');
    config()->set('telegram.topup_min_toman', 10000);
    config()->set('telegram.topup_max_toman', 50000000);

    Cache::flush();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
});

function hardeningWalletUser(int $telegramId = 73001, int $chatId = 74001): User
{
    $user = User::factory()->create();

    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'telegram_user_id' => $telegramId,
        'telegram_chat_id' => $chatId,
    ]);

    return $user;
}

it('does not credit the wallet when free Telegram top-up is disabled', function () {
    config()->set('telegram.allow_free_topup', false);

    $user = hardeningWalletUser();

    app(WalletFlow::class)->handleTopUpInput(
        (int) $user->telegramAccount->telegram_chat_id,
        (int) $user->telegramAccount->telegram_user_id,
        '100000',
    );

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

    expect($order->isWalletTopUp())->toBeTrue()
        ->and($order->total_toman)->toBe(100000)
        ->and($order->status)->toBe(Order::STATUS_PENDING)
        ->and($payment->status)->toBe(Payment::STATUS_PENDING)
        ->and((int) ($user->fresh()->wallet?->balance_toman ?? 0))->toBe(0)
        ->and($user->fresh()->wallet?->transactions()->count() ?? 0)->toBe(0);
});

it('auto-confirms a development top-up only when explicitly enabled and still uses the payment flow', function () {
    config()->set('telegram.allow_free_topup', true);

    $user = hardeningWalletUser(73002, 74002);

    app(WalletFlow::class)->handleTopUpInput(
        (int) $user->telegramAccount->telegram_chat_id,
        (int) $user->telegramAccount->telegram_user_id,
        '250000',
    );

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
    $wallet = $user->fresh()->wallet;

    expect($payment->status)->toBe(Payment::STATUS_PAID)
        ->and((int) $wallet->balance_toman)->toBe(250000)
        ->and($wallet->transactions()->count())->toBe(1)
        ->and($wallet->transactions()->first()->reference_id)->toBe($payment->id);
});

it('rejects top-up amounts below the configured minimum', function () {
    config()->set('telegram.allow_free_topup', true);

    $user = hardeningWalletUser(73003, 74003);

    app(WalletFlow::class)->handleTopUpInput(
        (int) $user->telegramAccount->telegram_chat_id,
        (int) $user->telegramAccount->telegram_user_id,
        '9999',
    );

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and((int) ($user->fresh()->wallet?->balance_toman ?? 0))->toBe(0);
});

it('rejects top-up amounts above the configured maximum', function () {
    config()->set('telegram.allow_free_topup', true);

    $user = hardeningWalletUser(73004, 74004);

    app(WalletFlow::class)->handleTopUpInput(
        (int) $user->telegramAccount->telegram_chat_id,
        (int) $user->telegramAccount->telegram_user_id,
        '50000001',
    );

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and((int) ($user->fresh()->wallet?->balance_toman ?? 0))->toBe(0);
});

it('rejects non-numeric top-up input instead of coercing it to a value', function () {
    config()->set('telegram.allow_free_topup', true);

    $user = hardeningWalletUser(73005, 74005);

    app(WalletFlow::class)->handleTopUpInput(
        (int) $user->telegramAccount->telegram_chat_id,
        (int) $user->telegramAccount->telegram_user_id,
        '999abc',
    );

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('deduplicates repeated top-up callbacks for the same amount', function () {
    config()->set('telegram.allow_free_topup', true);

    $user = hardeningWalletUser(73006, 74006);
    $flow = app(WalletFlow::class);
    $chatId = (int) $user->telegramAccount->telegram_chat_id;
    $telegramId = (int) $user->telegramAccount->telegram_user_id;

    $route = ['wallet', 'topup', 'amount', '100000'];

    $flow->handleCallback($chatId, 10, $telegramId, $route);
    $flow->handleCallback($chatId, 10, $telegramId, $route);

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Payment::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and((int) $user->fresh()->wallet->balance_toman)->toBe(100000);
});
