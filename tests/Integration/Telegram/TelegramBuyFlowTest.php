<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\WalletTransaction;
use App\Orders\OrderService;
use App\Outbox\OutboxTopic;
use App\Telegram\Callbacks\CallbackGrammar;
use App\Telegram\Flows\BuyMessages;
use Illuminate\Support\Facades\Http;
use Tests\Support\Telegram\BotFloor;

/**
 * Buying a server through the bot.
 *
 * Driven through the real pipeline every time — normalize, record, run the job
 * — because that pipeline is where the deduplication, the conversation lock and
 * the flow token live. A test that called the flow directly would prove the
 * screens work and prove nothing about whether a duplicate delivery buys twice.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->bot = BotFloor::open();
});

/** Walk the flow to the point just before payment, following real buttons. */
function walkToConfirm(BotFloor $bot): string
{
    $bot->say('خرید سرور');

    $bot->tap((string) BotFloor::lastButton('buy:p:'));
    $bot->tap((string) BotFloor::lastButton('buy:l:'));
    $bot->tap((string) BotFloor::lastButton('buy:i:'));
    $bot->tap((string) BotFloor::lastButton('buy:aup:'));

    return (string) BotFloor::lastButton('buy:ok:');
}

it('sells one server, end to end', function (): void {
    $balance = $this->bot->customer()->wallet_balance_toman;

    $this->bot->tap(walkToConfirm($this->bot));

    $order = Order::query()->sole();

    expect(Order::query()->count())->toBe(1)
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->user_id)->toBe($this->bot->customer()->getKey())
        // Exactly one debit, for exactly the quoted amount.
        ->and(WalletTransaction::query()->where('idempotency_key', $order->paymentIdempotencyKey())->count())->toBe(1)
        ->and($this->bot->customer()->wallet_balance_toman)->toBe($balance - $order->total_toman)
        // One purchase, one invoice.
        ->and(Invoice::query()->count())->toBe(1)
        // And a durable promise to build it, written with the money.
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningRequested)->count())->toBe(1);

    expect(BotFloor::transcript())->toContain($order->order_number);
});

it('turns a duplicated delivery into one purchase', function (): void {
    $confirm = walkToConfirm($this->bot);

    $update = $this->bot->tap($confirm);

    // The same delivery again: Telegram retried, or the queue did.
    $this->bot->run($update);
    $this->bot->run($update);

    expect(Order::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningRequested)->count())->toBe(1);
});

it('turns a second tap of the same confirm button into one purchase', function (): void {
    // A different update id, so deduplication cannot help: the customer really
    // did press it twice. What makes this one order is the purchase intent
    // generated when the flow began, which both taps carry.
    $confirm = walkToConfirm($this->bot);

    $this->bot->tap($confirm);
    $this->bot->tap($confirm);

    expect(Order::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1);
});

it('buys nothing from a keyboard belonging to an older flow', function (): void {
    $stale = walkToConfirm($this->bot);

    // They start again. The old keyboard is still on their screen.
    $this->bot->say('خرید سرور');

    $this->bot->tap($stale);

    expect(Order::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(0);
});

it('buys nothing once the conversation has been forgotten', function (): void {
    $confirm = walkToConfirm($this->bot);

    // Redis forgot it: the customer went away for longer than the TTL.
    app(App\Telegram\TelegramStateStore::class)->forget(BotFloor::TELEGRAM_USER_ID);

    $this->bot->tap($confirm);

    expect(Order::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(0);
});

it('buys nothing from a confirm button with a forged token', function (): void {
    walkToConfirm($this->bot);

    $this->bot->tap(CallbackGrammar::buyConfirm('deadbeef'));

    expect(Order::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(0);
});

it('will not charge the new price when the offer moved', function (): void {
    $confirm = walkToConfirm($this->bot);

    // An operator changes the price while the customer reads the screen.
    $this->bot->shop->price->forceFill(['selling_price_toman' => 2_000_000])->save();

    $this->bot->tap($confirm);

    expect(Order::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(0)
        ->and(BotFloor::transcript())->toContain(BuyMessages::OFFER_CHANGED);

    // Shown the new figure, and buying it takes another explicit confirmation.
    expect(BotFloor::transcript())->toContain(BuyMessages::money(2_000_000));

    $this->bot->tap((string) BotFloor::lastButton('buy:aup:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:ok:'));

    expect(Order::query()->sole()->total_toman)->toBe(2_000_000);
});

it('will not charge when the terms changed after the preview', function (): void {
    $confirm = walkToConfirm($this->bot);

    $this->bot->setAupVersion('2026-07');

    $this->bot->tap($confirm);

    expect(Order::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(0);

    // Accepting the new version explicitly is what lets it through.
    $this->bot->tap((string) BotFloor::lastButton('buy:aup:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:ok:'));

    expect(Order::query()->sole()->aup_version)->toBe('2026-07');
});

it('answers a repeat of a completed purchase with the original order', function (): void {
    $confirm = walkToConfirm($this->bot);
    $update = $this->bot->tap($confirm);

    $original = Order::query()->sole();

    // The world moves on, and then the same delivery arrives again.
    $this->bot->shop->price->forceFill(['selling_price_toman' => 9_999_999])->save();
    $this->bot->setLimit(SettingKey::SalesEnabled, null);

    $this->bot->run($update);

    $after = Order::query()->sole();

    // "Did my purchase succeed?", not "make today's purchase". Never repriced,
    // and not refused because sales are now off.
    expect($after->getKey())->toBe($original->getKey())
        ->and($after->total_toman)->toBe($original->total_toman)
        ->and(Order::query()->count())->toBe(1);
});

it('shows the price it will charge, and nothing about what it costs us', function (): void {
    walkToConfirm($this->bot);

    $transcript = BotFloor::transcript();
    $quote = app(App\Pricing\PricingService::class)->quoteNewSale($this->bot->shop->price);

    expect($transcript)->toContain(BuyMessages::money($quote->sellingPriceToman))
        // Provider cost, the rate and the margin are what this business pays
        // and earns. None of them belong on a customer's screen.
        ->and($transcript)->not->toContain($quote->providerCost)
        ->and($transcript)->not->toContain($quote->exchangeRate)
        ->and($transcript)->not->toContain($quote->grossMarginToman)
        ->and($transcript)->not->toContain($quote->providerCode);
});

it('refuses a suspended customer without touching their wallet', function (): void {
    $confirm = walkToConfirm($this->bot);

    $this->bot->shop->customer->forceFill(['status' => 'suspended'])->save();
    $balance = $this->bot->customer()->wallet_balance_toman;

    $this->bot->tap($confirm);

    expect(Order::query()->count())->toBe(0)
        ->and($this->bot->customer()->wallet_balance_toman)->toBe($balance);
});

it('will not order what it cannot pay for', function (): void {
    // Priced beyond what this customer holds.
    $this->bot->shop->price->forceFill(['selling_price_toman' => 9_000_000])->save();

    $this->bot->say('خرید سرور');
    $this->bot->tap((string) BotFloor::lastButton('buy:p:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:l:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:i:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:aup:'));

    // No order is created merely to discover the obvious shortfall, no pay
    // button is offered, and the customer is told what they are short by.
    expect(Order::query()->count())->toBe(0)
        ->and(BotFloor::lastButton('buy:ok:'))->toBeNull()
        ->and(BotFloor::transcript())->toContain('کسری')
        ->and(BotFloor::transcript())->toContain(BuyMessages::money(4_000_000));
});

it('calls no provider while the customer is on the phone', function (): void {
    // The whole flow, on the interactive worker. Not one provider row is
    // touched: a create, a reboot or a delete can block for minutes, and a
    // customer's tap must never sit inside somebody else's network.
    $this->bot->tap(walkToConfirm($this->bot));

    expect(App\Cloud\Fake\Models\FakeProviderServer::query()->count())->toBe(0)
        ->and(App\Cloud\Fake\Models\FakeProviderAction::query()->count())->toBe(0)
        ->and(Server::query()->count())->toBe(0);

    // Every HTTP call made was to Telegram.
    foreach (Http::recorded() as $exchange) {
        expect($exchange[0]->url())->toStartWith('https://api.telegram.test/');
    }
});

it('records what the button meant, never the string it sent', function (): void {
    $this->bot->say('خرید سرور');

    $update = $this->bot->tap((string) BotFloor::lastButton('buy:p:'));

    $stored = json_encode($update->fresh()->toArray(), JSON_THROW_ON_ERROR);

    expect($update->fresh()->action->value)->toBe('buy.product')
        // The normalized hints are kept; the opaque callback string is not.
        ->and($update->fresh()->metadata['param_id'])->toBeInt()
        ->and($stored)->not->toContain('buy:p:');
});

it('cancels without buying anything', function (): void {
    walkToConfirm($this->bot);

    $this->bot->tap((string) BotFloor::lastButton('buy:x:'));

    expect(Order::query()->count())->toBe(0)
        ->and(BotFloor::transcript())->toContain(BuyMessages::CANCELLED);

    // And the flow is gone, so its keyboard is inert.
    expect(app(App\Telegram\TelegramStateStore::class)->has(BotFloor::TELEGRAM_USER_ID))->toBeFalse();
});

it('derives the order key from the purchase intent alone', function (): void {
    $this->bot->tap(walkToConfirm($this->bot));

    $key = Order::query()->sole()->idempotency_key;

    expect($key)->toStartWith('telegram:purchase:')
        // A UUID, not a timestamp, a counter or anything a customer chose.
        ->and(strlen($key))->toBe(strlen('telegram:purchase:') + 36)
        ->and($key)->not->toContain((string) BotFloor::TELEGRAM_USER_ID);
});

it('uses the same key however the confirmation is repeated', function (): void {
    $intentId = '018f0000-0000-7000-8000-000000000001';

    expect(App\Telegram\Flows\BuyServerFlow::orderKey($intentId))
        ->toBe(App\Telegram\Flows\BuyServerFlow::orderKey($intentId))
        ->toBe('telegram:purchase:'.$intentId);
});

it('places one order for one intent even when placed directly twice', function (): void {
    // Below the flow: the same intent through OrderService twice is one order,
    // because a unique index says so rather than because a check ran.
    $this->bot->tap(walkToConfirm($this->bot));

    $order = Order::query()->sole();
    $orders = app(OrderService::class);

    $again = $orders->place(new App\Orders\Data\PurchaseIntent(
        user: $this->bot->customer(),
        locationPrice: $this->bot->shop->price,
        acceptedAupVersion: $order->aup_version,
        aupAccepted: true,
        idempotencyKey: $order->idempotency_key,
    ));

    expect($again->getKey())->toBe($order->getKey())
        ->and(Order::query()->count())->toBe(1);
});
