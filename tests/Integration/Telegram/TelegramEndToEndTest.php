<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Cloud\Fake\Models\FakeProviderServer;
use App\Enums\OrderStatus;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\ProcessOutboxMessageJob;
use App\Jobs\ProvisionOrderJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Outbox\OutboxDispatcher;
use App\Outbox\OutboxTopic;
use Illuminate\Support\Facades\Http;
use Tests\Support\Telegram\BotFloor;

/**
 * A customer buying a server, from the first message to the one that says it is
 * ready.
 *
 * Every step goes through the machinery that would run in production: the
 * Telegram pipeline, the order boundary, the transactional outbox, the
 * provisioning worker and the real simulated provider with a database behind
 * it. Nothing is reached into and set by hand between the steps — that is the
 * point, because the interesting failures all live in the seams.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->bot = BotFloor::open();
});

it('takes a customer from hello to a running server', function (): void {
    $balance = $this->bot->customer()->wallet_balance_toman;

    // 1. They say hello and are recognised.
    $this->bot->say('/start');

    expect(BotFloor::transcript())->toContain('خوش آمدید');

    // 2. They choose a server, a place, an image, see the price, accept the
    //    terms and pay — following the buttons the bot actually drew.
    $this->bot->say('خرید سرور');
    $this->bot->tap((string) BotFloor::lastButton('buy:p:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:l:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:i:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:aup:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:ok:'));

    $order = Order::query()->sole();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($this->bot->customer()->wallet_balance_toman)->toBe($balance - $order->total_toman)
        ->and(Invoice::query()->count())->toBe(1)
        // Nothing has been built yet, and no provider has been contacted.
        ->and(FakeProviderServer::query()->count())->toBe(0);

    // 3. The outbox sweep finds the promise and the notification worker acts on
    //    it. No provisioning job was dispatched by the request itself.
    $requested = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningRequested)->sole();

    expect(app(OutboxDispatcher::class)->due()->pluck('id')->all())->toContain($requested->getKey());

    app()->call([new ProcessOutboxMessageJob((int) $requested->getKey()), 'handle']);

    // 4. The provisioning worker builds it, at the provider.
    app()->call([new ProvisionOrderJob((int) $order->getKey()), 'handle']);

    $server = Server::query()->sole();
    $subscription = Subscription::query()->sole();

    expect(FakeProviderServer::query()->count())->toBe(1)
        ->and($server->user_id)->toBe($this->bot->customer()->getKey())
        ->and($server->order_id)->toBe($order->getKey())
        ->and($server->status)->toBe(ServerStatus::Active)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->periodSeconds())->toBe(Subscription::PERIOD_SECONDS)
        ->and($order->fresh()->status)->toBe(OrderStatus::Provisioned);

    // 5. The customer is told, through the outbox rather than from inside the
    //    transaction that made it true.
    $success = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole();

    app()->call([new ProcessOutboxMessageJob((int) $success->getKey()), 'handle']);

    expect(BotFloor::transcript())->toContain($server->name)
        ->and(BotFloor::transcript())->toContain($order->order_number);

    // 6. And it is theirs to manage.
    $this->bot->say('سرورهای من');

    expect(BotFloor::transcript())->toContain($server->name);

    // One of everything, all the way down.
    expect(Order::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1);

    // And the ledger still explains the balance exactly.
    expect($this->bot->customer()->wallet_balance_toman)
        ->toBe((int) WalletTransaction::query()->where('user_id', $this->bot->customer()->getKey())->sum('amount_toman'));
});

it('builds one server even when every step is delivered twice', function (): void {
    $this->bot->say('خرید سرور');
    $this->bot->tap((string) BotFloor::lastButton('buy:p:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:l:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:i:'));
    $this->bot->tap((string) BotFloor::lastButton('buy:aup:'));

    $confirm = $this->bot->tap((string) BotFloor::lastButton('buy:ok:'));
    $this->bot->run($confirm);

    $order = Order::query()->sole();
    $requested = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningRequested)->sole();

    app()->call([new ProcessOutboxMessageJob((int) $requested->getKey()), 'handle']);
    app()->call([new ProcessOutboxMessageJob((int) $requested->getKey()), 'handle']);

    app()->call([new ProvisionOrderJob((int) $order->getKey()), 'handle']);
    app()->call([new ProvisionOrderJob((int) $order->getKey()), 'handle']);

    $success = OutboxMessage::query()->where('topic', OutboxTopic::ProvisioningSucceeded)->sole();

    app()->call([new ProcessOutboxMessageJob((int) $success->getKey()), 'handle']);
    app()->call([new ProcessOutboxMessageJob((int) $success->getKey()), 'handle']);

    // Every layer deduplicates on something durable, so doubling everything
    // changes nothing.
    expect(Order::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', 'debit')->count())->toBe(1)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(FakeProviderServer::query()->count())->toBe(1)
        ->and(Server::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(App\Models\NotificationLog::query()->count())->toBe(1);
});
