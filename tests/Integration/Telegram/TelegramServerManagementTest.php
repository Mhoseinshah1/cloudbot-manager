<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Cloud\Fake\Models\FakeProviderAction;
use App\Enums\ServerActionType;
use App\Jobs\DeleteTelegramMessageJob;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Provisioning\ProvisioningService;
use App\Support\Queues;
use App\Telegram\Callbacks\CallbackGrammar;
use App\Telegram\Flows\ServerMessages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Provisioning\Simulator;
use Tests\Support\Telegram\BotFloor;

/**
 * Looking at servers, and operating them, through the bot.
 *
 * The tests that matter here are the ones about somebody else's server. An id
 * in a callback is a string a customer controls, so every one of these drives a
 * real second customer's id through the real pipeline and checks that nothing
 * happens — not that an exception was thrown somewhere, but that no action was
 * recorded and no provider was touched.
 */
beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]])]);

    $this->bot = BotFloor::open();

    app(ProvisioningService::class)->provision($this->bot->shop->paidOrder());

    $this->server = Server::query()->sole();
});

/** A second customer, with a server of their own. */
function otherCustomersServer(): Server
{
    $stranger = User::factory()->fromTelegram()->create();

    // A real order of their own: a server without one would not be a server
    // this system could have sold, and the test would be about a row shape
    // rather than about ownership.
    $order = Order::factory()->create([
        'user_id' => $stranger->getKey(),
        'product_id' => test()->bot->shop->product->getKey(),
        'product_location_price_id' => test()->bot->shop->price->getKey(),
    ]);

    return Server::query()->create([
        'user_id' => $stranger->getKey(),
        'order_id' => $order->getKey(),
        'product_id' => test()->bot->shop->product->getKey(),
        'provider_id' => test()->bot->shop->provider->getKey(),
        'provider_location_id' => test()->bot->shop->location->getKey(),
        'provider_server_id' => 'stranger-'.bin2hex(random_bytes(4)),
        'provisioning_uuid' => (string) Illuminate\Support\Str::uuid(),
        'name' => 'not-yours',
        'plan_snapshot' => [], 'image_snapshot' => [],
        'billing_mode' => 'monthly', 'provider_cost' => '1.000000',
        'provider_currency' => 'EUR', 'exchange_rate' => '1.00000000',
        'local_cost_toman' => '1.000000', 'selling_price_toman' => 1,
        'gross_margin_toman' => '0.000000',
    ]);
}

it('lists only the customer\'s own servers', function (): void {
    $stranger = otherCustomersServer();

    $this->bot->say('سرورهای من');

    expect(BotFloor::transcript())->toContain($this->server->name)
        ->and(BotFloor::transcript())->not->toContain($stranger->name);
});

it('shows a server\'s details without calling the provider', function (): void {
    $before = FakeProviderAction::query()->count();

    $this->bot->tap(CallbackGrammar::serverView((int) $this->server->getKey()));

    expect(BotFloor::transcript())->toContain($this->server->name)
        // Opening a details page must not depend on a third party being up.
        ->and(FakeProviderAction::query()->count())->toBe($before);
});

it('says the same thing about a stranger\'s server as a missing one', function (): void {
    $stranger = otherCustomersServer();

    $this->bot->tap(CallbackGrammar::serverView((int) $stranger->getKey()));
    $strangerAnswer = BotFloor::transcript();

    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->bot->tap(CallbackGrammar::serverView(999_999));

    // Two different answers would make the bot a way of discovering which ids
    // are real.
    expect($strangerAnswer)->toContain(ServerMessages::NOT_FOUND)
        ->and(BotFloor::transcript())->toContain(ServerMessages::NOT_FOUND)
        ->and($strangerAnswer)->not->toContain($stranger->name);
});

it('records nothing for an action on a stranger\'s server', function (string $callback): void {
    $stranger = otherCustomersServer();

    $this->bot->tap(str_replace('{id}', (string) $stranger->getKey(), $callback));

    expect(ServerAction::query()->count())->toBe(0)
        ->and(FakeProviderAction::query()->where('command', '!=', 'create')->count())->toBe(0);
})->with([
    'view' => ['srv:v:{id}'],
    'power on' => ['srv:on:{id}'],
    'power off' => ['srv:off:{id}'],
    'reboot' => ['srv:rb:{id}'],
    'delete' => ['srv:del:{id}'],
    'reveal the password' => ['srv:pw:{id}'],
]);

it('shows no other customer\'s invoice or wallet history', function (): void {
    $stranger = User::factory()->fromTelegram()->create();

    $strangerInvoice = Invoice::query()->create([
        'user_id' => $stranger->getKey(),
        'number' => 'INV-STRANGER',
        'type' => 'server_purchase',
        'amount_toman' => 12_345,
        'status' => 'issued',
        'issued_at' => now(),
        'line_items' => [],
    ]);

    app(App\Wallet\WalletService::class)->credit($stranger, 777_777, 'stranger-key', 'Stranger top-up');

    $this->bot->say('فاکتورها');
    $this->bot->tap(CallbackGrammar::invoiceView((int) $strangerInvoice->getKey()));
    $this->bot->say('کیف پول');

    $transcript = BotFloor::transcript();

    expect($transcript)->not->toContain('INV-STRANGER')
        ->and($transcript)->not->toContain('777,777')
        ->and($transcript)->not->toContain('Stranger top-up');
});

it('offers only the buttons the provider can honour', function (): void {
    $this->bot->tap(CallbackGrammar::serverView((int) $this->server->getKey()));

    $withCapabilities = BotFloor::buttonsSent();

    expect($withCapabilities)->toContain(CallbackGrammar::serverPowerOn((int) $this->server->getKey()))
        ->and($withCapabilities)->toContain(CallbackGrammar::serverReboot((int) $this->server->getKey()));

    // A provider that implements neither offers neither.
    Simulator::coreOnly();
    Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->bot->tap(CallbackGrammar::serverView((int) $this->server->getKey()));

    expect(BotFloor::buttonsSent())->not->toContain(CallbackGrammar::serverReboot((int) $this->server->getKey()))
        // Delete stays: it is in the core contract every provider implements.
        ->and(BotFloor::buttonsSent())->toContain(CallbackGrammar::serverDelete((int) $this->server->getKey()));
});

it('refuses an action the provider does not implement, however it arrives', function (): void {
    Simulator::coreOnly();

    $this->bot->tap(CallbackGrammar::serverReboot((int) $this->server->getKey()));

    // No button was drawn for it; sending its callback anyway changes nothing.
    expect(ServerAction::query()->count())->toBe(0);
});

it('records one action for a re-delivered tap', function (): void {
    $update = $this->bot->tap(CallbackGrammar::serverPowerOff((int) $this->server->getKey()));

    $this->bot->run($update);
    $this->bot->run($update);

    expect(ServerAction::query()->count())->toBe(1)
        ->and(ServerAction::query()->sole()->action)->toBe(ServerActionType::PowerOff);
});

it('does no provider work on the interactive worker', function (): void {
    $before = FakeProviderAction::query()->count();

    $this->bot->tap(CallbackGrammar::serverPowerOff((int) $this->server->getKey()));
    $this->bot->tap(CallbackGrammar::serverReboot((int) $this->server->getKey()));

    // Two actions recorded, nothing sent anywhere. The work happens on the
    // worker built for waiting.
    expect(ServerAction::query()->count())->toBe(2)
        ->and(FakeProviderAction::query()->count())->toBe($before)
        ->and(OutboxMessage::query()->where('topic', 'server.action_requested')->count())->toBe(2);
});

it('never deletes on the first press', function (): void {
    $this->bot->tap(CallbackGrammar::serverDelete((int) $this->server->getKey()));

    // A confirmation screen, and nothing recorded.
    expect(ServerAction::query()->count())->toBe(0)
        ->and(BotFloor::transcript())->toContain('مطمئن هستید')
        ->and(BotFloor::lastButton('srv:delok:'))->not->toBeNull();
});

it('deletes only after an explicit confirmation', function (): void {
    $this->bot->tap(CallbackGrammar::serverDelete((int) $this->server->getKey()));
    $this->bot->tap((string) BotFloor::lastButton('srv:delok:'));

    $action = ServerAction::query()->sole();

    expect($action->action)->toBe(ServerActionType::Delete)
        ->and($action->server_id)->toBe($this->server->getKey());
});

it('deletes nothing from a stale confirmation', function (): void {
    $this->bot->tap(CallbackGrammar::serverDelete((int) $this->server->getKey()));
    $stale = (string) BotFloor::lastButton('srv:delok:');

    // They back out, and later ask again. The second screen writes a fresh
    // intent, and the first confirmation is still sitting on their phone.
    $this->bot->tap(CallbackGrammar::serverDelete((int) $this->server->getKey()));
    $current = (string) BotFloor::lastButton('srv:delok:');

    expect($current)->not->toBe($stale);

    $this->bot->tap($stale);

    expect(ServerAction::query()->count())->toBe(0);

    // And the live one still works, so this is a stale-token check rather than
    // deletion being broken.
    $this->bot->tap($current);

    expect(ServerAction::query()->count())->toBe(1);
});

it('aims a confirmation only at the server it was drawn for', function (): void {
    // A second server of the customer's own, so the danger is real: a
    // confirmation drawn for one machine must not be able to destroy another.
    app(ProvisioningService::class)->provision($this->bot->shop->paidOrder());

    $servers = Server::query()->orderBy('id')->get();
    $first = $servers->first();
    $second = $servers->last();

    expect($first->getKey())->not->toBe($second->getKey());

    $this->bot->tap(CallbackGrammar::serverDelete((int) $first->getKey()));
    $staleForFirst = (string) BotFloor::lastButton('srv:delok:');

    // They go and look at the other one and confirm there instead.
    $this->bot->tap(CallbackGrammar::serverDelete((int) $second->getKey()));
    $this->bot->tap((string) BotFloor::lastButton('srv:delok:'));

    expect(ServerAction::query()->sole()->server_id)->toBe($second->getKey());

    // The older confirmation is inert, and in particular does not delete the
    // machine that is now selected.
    $this->bot->tap($staleForFirst);

    expect(ServerAction::query()->count())->toBe(1)
        ->and(ServerAction::query()->sole()->server_id)->toBe($second->getKey());
});

it('deletes nothing from a forged confirmation', function (): void {
    $this->bot->tap(CallbackGrammar::serverDelete((int) $this->server->getKey()));

    $this->bot->tap(CallbackGrammar::serverDeleteConfirm('deadbeef'));

    expect(ServerAction::query()->count())->toBe(0);
});

it('records one delete however many times the confirmation is delivered', function (): void {
    $this->bot->tap(CallbackGrammar::serverDelete((int) $this->server->getKey()));
    $confirm = (string) BotFloor::lastButton('srv:delok:');

    $update = $this->bot->tap($confirm);
    $this->bot->run($update);
    $this->bot->tap($confirm);

    // The intent is spent on first use, so the second tap finds nothing live.
    expect(ServerAction::query()->count())->toBe(1);
});

it('reveals a root password only to its owner, once, and deletes the message', function (): void {
    // Generated at runtime: a credential-shaped literal in the repository is a
    // secret-scanner finding whether or not it is real.
    $password = 'Synthetic-'.bin2hex(random_bytes(12));

    $this->server->forceFill(['root_password_encrypted' => $password])->save();

    Queue::fake();

    $this->bot->tap(CallbackGrammar::serverRevealPassword((int) $this->server->getKey()));

    expect(BotFloor::transcript())->toContain($password)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerPasswordRevealed)->count())->toBe(1);

    // Scheduled for deletion, carrying a chat id and a message id and never
    // the password itself.
    Queue::assertPushed(DeleteTelegramMessageJob::class, function (DeleteTelegramMessageJob $job) use ($password): bool {
        return $job->messageId === 4242
            && $job->chatId === BotFloor::TELEGRAM_USER_ID
            && ! str_contains(serialize($job), $password);
    });

    expect(DeleteTelegramMessageJob::queueName())->toBe(Queues::Notifications->value);
});

it('keeps a revealed password out of everything that is kept', function (): void {
    $password = 'Synthetic-'.bin2hex(random_bytes(12));

    $this->server->forceFill(['root_password_encrypted' => $password])->save();

    $this->bot->tap(CallbackGrammar::serverRevealPassword((int) $this->server->getKey()));
    $this->bot->tap(CallbackGrammar::serverView((int) $this->server->getKey()));
    $this->bot->say('سرورهای من');

    $kept = json_encode([
        AuditLog::query()->get(['before', 'after', 'metadata'])->toArray(),
        ServerAction::query()->get(['metadata'])->toArray(),
        OutboxMessage::query()->get(['payload'])->toArray(),
        NotificationLog::query()->get(['summary'])->toArray(),
        App\Models\TelegramUpdate::query()->get(['metadata'])->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($kept)->not->toContain($password);

    // Not in the details screen or the list either: it has exactly one route
    // to a customer, and that route is the deliberate reveal.
    $conversationState = app(App\Telegram\TelegramStateStore::class)->get(BotFloor::TELEGRAM_USER_ID);

    expect(json_encode($conversationState, JSON_THROW_ON_ERROR))->not->toContain($password);

    // Encrypted at rest: a database dump shows nothing.
    $raw = (string) Illuminate\Support\Facades\DB::table('servers')
        ->where('id', $this->server->getKey())
        ->value('root_password_encrypted');

    expect($raw)->not->toContain($password)->and($raw)->not->toBe('');
});

it('reveals nothing to a stranger', function (): void {
    $password = 'Synthetic-'.bin2hex(random_bytes(12));
    $stranger = otherCustomersServer();
    $stranger->forceFill(['root_password_encrypted' => $password])->save();

    $this->bot->tap(CallbackGrammar::serverRevealPassword((int) $stranger->getKey()));

    expect(BotFloor::transcript())->not->toContain($password)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerPasswordRevealed)->count())->toBe(0);
});

it('reveals nothing to a suspended customer', function (): void {
    $password = 'Synthetic-'.bin2hex(random_bytes(12));
    $this->server->forceFill(['root_password_encrypted' => $password])->save();
    $this->bot->shop->customer->forceFill(['status' => 'suspended'])->save();

    $this->bot->tap(CallbackGrammar::serverRevealPassword((int) $this->server->getKey()));

    expect(BotFloor::transcript())->not->toContain($password)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerPasswordRevealed)->count())->toBe(0);
});

it('offers no reveal when no password is held', function (): void {
    // Provisioning now stores the credential the provider issued, so the
    // no-password case has to be arranged rather than assumed. It is a real
    // case: a provider that authenticates by key issues none.
    $this->server->forceFill(['root_password_encrypted' => null])->save();

    $this->bot->tap(CallbackGrammar::serverView((int) $this->server->getKey()));

    expect(BotFloor::buttonsSent())
        ->not->toContain(CallbackGrammar::serverRevealPassword((int) $this->server->getKey()));
});

it('reveals the credential provisioning actually stored, to its owner only', function (): void {
    // End to end: the password the provider issued at create time, through the
    // encrypted column, to the one person entitled to see it. Nothing is set up
    // by hand here — the credential arrived through the create contract.
    $issued = (string) $this->server->fresh()->root_password_encrypted;

    expect($issued)->not->toBe('')
        // Confirmed by presenting it to the provider, which is the only way to
        // learn what password a machine has: the simulator keeps a verifier.
        ->and(Simulator::plain()->credentialMatches(
            (string) $this->server->provider_server_id, $issued,
        ))->toBeTrue();

    $this->bot->tap(CallbackGrammar::serverRevealPassword((int) $this->server->getKey()));

    expect(BotFloor::transcript())->toContain($issued)
        ->and(AuditLog::query()->where('event', AuditEvent::ServerPasswordRevealed)->count())->toBe(1);

    // And to nobody else. A stranger tapping the same button sees nothing and
    // leaves no reveal behind.
    $stranger = otherCustomersServer();
    $stranger->forceFill(['root_password_encrypted' => $issued])->save();

    $this->bot->tap(CallbackGrammar::serverRevealPassword((int) $stranger->getKey()));

    expect(AuditLog::query()->where('event', AuditEvent::ServerPasswordRevealed)->count())->toBe(1);
});

it('refuses every management action from a suspended customer', function (string $callback): void {
    $this->bot->shop->customer->forceFill(['status' => 'suspended'])->save();

    $this->bot->tap(str_replace('{id}', (string) $this->server->getKey(), $callback));

    expect(ServerAction::query()->count())->toBe(0)
        ->and(FakeProviderAction::query()->where('command', '!=', 'create')->count())->toBe(0);
})->with([
    'power on' => ['srv:on:{id}'],
    'power off' => ['srv:off:{id}'],
    'reboot' => ['srv:rb:{id}'],
    'delete' => ['srv:del:{id}'],
]);

it('shows a customer their own wallet and invoices', function (): void {
    $this->bot->say('کیف پول');
    $this->bot->say('فاکتورها');

    $transcript = BotFloor::transcript();
    $invoice = Invoice::query()->sole();

    expect($transcript)->toContain($invoice->number)
        // Honest about what the bot cannot do rather than offering a top-up
        // button that would not work.
        ->and($transcript)->toContain('افزایش موجودی');

    // And the ledger it shows is this customer's.
    expect(WalletTransaction::query()->where('user_id', $this->bot->customer()->getKey())->count())
        ->toBeGreaterThan(0);
});

it('shows no other telegram account\'s conversation', function (): void {
    // A second Telegram identity for a different person. State is keyed by the
    // numeric id, so one conversation cannot read another's.
    $stranger = User::factory()->fromTelegram()->create();

    TelegramAccount::query()->create([
        'user_id' => $stranger->getKey(),
        'telegram_user_id' => 6_600_222_222,
        'telegram_chat_id' => 6_600_222_222,
    ]);

    $this->bot->tap(CallbackGrammar::serverDelete((int) $this->server->getKey()));

    $mine = app(App\Telegram\TelegramStateStore::class)->get(BotFloor::TELEGRAM_USER_ID);
    $theirs = app(App\Telegram\TelegramStateStore::class)->get(6_600_222_222);

    expect($mine)->not->toBeNull()->and($theirs)->toBeNull();
});
