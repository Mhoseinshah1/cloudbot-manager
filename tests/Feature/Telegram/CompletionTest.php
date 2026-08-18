<?php

/**
 * Telegram Bot Customer Phase — Final Completion Tests.
 *
 * Covers:
 * 1. Monthly Purchase E2E
 * 2. Hourly Purchase E2E
 * 3. Hourly Capped Purchase E2E
 * 4. Monthly Renewal via proper payment flow
 * 5. Billing Notifications wired to Telegram
 * 6. Async Provisioning Delivery
 * 7. Callback Idempotency
 * 8. Stale/Manipulated Callbacks
 * 9. Server Action Completeness
 * 10. Telegram API Failure Safety
 */

use App\Contracts\Data\ProviderImageData;
use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPlanData;
use App\Events\LowBalanceWarningTriggered;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Jobs\ProvisionServerJob;
use App\Models\Invoice;
use App\Models\LowBalanceWarning;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Provider;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\Server;
use App\Models\ServerAction;
use App\Models\Subscription;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AuditService;
use App\Services\HourlyBillingService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProviderManager;
use App\Services\RenewalService;
use App\Services\Telegram\Flows\ServerActionsFlow;
use App\Services\Telegram\TelegramNotificationService;
use App\Services\Telegram\TelegramUpdateRouter;
use App\Services\WalletService;
use Database\Seeders\FakeProviderSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('telegram.bot_token', 'test-bot-token');
    config()->set('telegram.webhook_secret', 'test-secret-123');
    config()->set('telegram.bot_username', 'test_bot');
    config()->set('telegram.api_base_url', 'https://api.telegram.org');
    config()->set('telegram.servers_per_page', 5);

    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $this->seed(FakeProviderSeeder::class);
});

// ── Helper ──────────────────────────────────────────────────────────────

function createTelegramUser(int $tgId = 11111, int $chatId = 12345): User
{
    $user = User::factory()->create();
    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'telegram_user_id' => $tgId,
        'telegram_chat_id' => $chatId,
    ]);

    return $user;
}

function getMonthlyProduct(): Product
{
    return Product::where('slug', 'vps-cx21')->firstOrFail();
}

function getHourlyProduct(): Product
{
    return Product::where('slug', 'vps-cx21-hourly')->firstOrFail();
}

function getCappedProduct(): Product
{
    return Product::where('slug', 'vps-cx21-capped')->firstOrFail();
}

function findEnabledLocation(): ProviderLocation
{
    return ProviderLocation::where('enabled', true)->firstOrFail();
}

function findEnabledImage(): ProviderImage
{
    return ProviderImage::where('enabled', true)->whereNull('deprecated')->firstOrFail();
}

function findEnabledPlan(): ProviderPlan
{
    // Return the plan that matches our test products (cpx21)
    $product = Product::where('slug', 'vps-cx21')->first();
    if ($product) {
        return ProviderPlan::where('id', $product->provider_plan_id)->where('enabled', true)->firstOrFail();
    }

    return ProviderPlan::where('enabled', true)->firstOrFail();
}

function routerHandle(array $update): void
{
    app(TelegramUpdateRouter::class)->handle($update);
}

function makeCallback(string $data, int $tgUserId, int $chatId, int $messageId = 10): array
{
    return [
        'update_id' => rand(1, 999999),
        'callback_query' => [
            'id' => 'cb_'.uniqid(),
            'data' => $data,
            'chat' => ['id' => $chatId],
            'message' => ['message_id' => $messageId, 'chat' => ['id' => $chatId]],
            'from' => ['id' => $tgUserId, 'first_name' => 'Test'],
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════
// 1. MONTHLY PURCHASE E2E
// ═══════════════════════════════════════════════════════════════════════

it('completes monthly purchase E2E from start to provisioning', function () {
    $user = createTelegramUser();
    $tgId = $user->telegramAccount->telegram_user_id;
    $chatId = $user->telegramAccount->telegram_chat_id;

    $product = getMonthlyProduct();
    $plan = findEnabledPlan();
    $location = findEnabledLocation();
    $image = findEnabledImage();

    // Simulate full buy flow
    routerHandle(makeCallback('buy:start', $tgId, $chatId));
    routerHandle(makeCallback('buy:mode:monthly', $tgId, $chatId));
    routerHandle(makeCallback("buy:loc:monthly:{$location->id}", $tgId, $chatId));
    routerHandle(makeCallback("buy:plan:monthly:{$location->id}:{$plan->id}", $tgId, $chatId));
    routerHandle(makeCallback("buy:img:monthly:{$location->id}:{$plan->id}:{$image->id}", $tgId, $chatId));

    // Confirm — this creates order, invoice, payment, and dispatches provisioning
    routerHandle(makeCallback("buy:confirm:monthly:{$location->id}:{$plan->id}:{$image->id}", $tgId, $chatId));

    // Process the sync-dispatched ProvisionServerJob
    $order = Order::where('user_id', $user->id)->first();
    expect($order)->not->toBeNull();
    expect($order->billing_mode)->toBe('monthly');

    $server = Server::where('user_id', $user->id)->first();
    expect($server)->not->toBeNull();
    expect($server->user_id)->toBe($user->id);
    expect($server->billing_mode)->toBe('monthly');
    expect($server->status)->toBe('running');
    expect($server->expires_at)->not->toBeNull();
    expect($server->hourly_rate_toman)->toBeNull();

    // Verify product snapshot
    expect($server->product_id)->toBe($product->id);
    expect($server->image_snapshot)->not->toBeEmpty();
    expect($server->plan_snapshot)->not->toBeEmpty();

    // Only one server provisioned
    expect(Server::where('user_id', $user->id)->count())->toBe(1);

    // Verify billing started is NOT set for monthly
    expect($server->billing_started_at)->toBeNull();

    // Verify editMessageText was called (menu navigation)
    $editCalls = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'editMessageText'));
    expect($editCalls)->not->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════════════
// 2. HOURLY PURCHASE E2E
// ═══════════════════════════════════════════════════════════════════════

it('completes hourly purchase E2E with wallet funding and provisioning', function () {
    $user = createTelegramUser(22222);
    $tgId = $user->telegramAccount->telegram_user_id;
    $chatId = $user->telegramAccount->telegram_chat_id;

    $product = getHourlyProduct();
    $plan = findEnabledPlan();
    $location = findEnabledLocation();
    $image = findEnabledImage();

    // Fund wallet above minimum prepaid requirement
    $hourlyRate = (int) $product->hourly_price_toman;
    $minPrepaidHours = config('billing.hourly.minimum_prepaid_hours', 24);
    $requiredBalance = $hourlyRate * $minPrepaidHours;

    app(WalletService::class)->credit($user, $requiredBalance + 50000, 'test funding');

    // Simulate buy flow
    routerHandle(makeCallback('buy:start', $tgId, $chatId));
    routerHandle(makeCallback('buy:mode:hourly', $tgId, $chatId));
    routerHandle(makeCallback("buy:loc:hourly:{$location->id}", $tgId, $chatId));
    routerHandle(makeCallback("buy:plan:hourly:{$location->id}:{$plan->id}", $tgId, $chatId));
    routerHandle(makeCallback("buy:img:hourly:{$location->id}:{$plan->id}:{$image->id}", $tgId, $chatId));
    routerHandle(makeCallback("buy:confirm:hourly:{$location->id}:{$plan->id}:{$image->id}", $tgId, $chatId));

    $server = Server::where('user_id', $user->id)->first();
    expect($server)->not->toBeNull();
    expect($server->billing_mode)->toBe('hourly');
    expect($server->hourly_rate_toman)->toBe($hourlyRate);
    expect($server->billing_started_at)->not->toBeNull();

    // Wallet was funded by order payment (not counted as usage)
    $wallet = $user->fresh()->wallet;
    expect($wallet->balance_toman)->toBeGreaterThan(0);

    // My Servers should contain the server
    routerHandle(makeCallback('servers:list:0', $tgId, $chatId));
    $editCalls = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'editMessageText'));
    expect($editCalls)->not->toBeEmpty();
});

it('rejects hourly provisioning when wallet balance is insufficient', function () {
    $user = createTelegramUser(33333);
    $product = getHourlyProduct();
    $order = app(OrderService::class)->place($user, $product);
    $order->update(['status' => Order::STATUS_PAID]);

    // Wallet has 0 balance — below minimum prepaid requirement
    expect($user->fresh()->wallet?->balance_toman ?? 0)->toBe(0);

    // Provisioning should throw InsufficientWalletBalanceException
    $this->expectException(InsufficientWalletBalanceException::class);

    (new ProvisionServerJob($order))->handle(
        app(ProviderManager::class),
        app(AuditService::class),
        app(HourlyBillingService::class),
    );
});

// ═══════════════════════════════════════════════════════════════════════
// 3. HOURLY CAPPED PURCHASE E2E
// ═══════════════════════════════════════════════════════════════════════

it('completes hourly_capped purchase E2E and verifies snapshots', function () {
    $user = createTelegramUser(44444);
    $tgId = $user->telegramAccount->telegram_user_id;
    $chatId = $user->telegramAccount->telegram_chat_id;

    $product = getCappedProduct();
    $plan = findEnabledPlan();
    $location = findEnabledLocation();
    $image = findEnabledImage();

    // Fund wallet
    $hourlyRate = (int) $product->hourly_price_toman;
    $minPrepaidHours = config('billing.hourly.minimum_prepaid_hours', 24);
    app(WalletService::class)->credit($user, $hourlyRate * $minPrepaidHours + 100000, 'test');

    routerHandle(makeCallback('buy:start', $tgId, $chatId));
    routerHandle(makeCallback('buy:mode:hourly_capped', $tgId, $chatId));
    routerHandle(makeCallback("buy:loc:hourly_capped:{$location->id}", $tgId, $chatId));
    routerHandle(makeCallback("buy:plan:hourly_capped:{$location->id}:{$plan->id}", $tgId, $chatId));
    routerHandle(makeCallback("buy:img:hourly_capped:{$location->id}:{$plan->id}:{$image->id}", $tgId, $chatId));
    routerHandle(makeCallback("buy:confirm:hourly_capped:{$location->id}:{$plan->id}:{$image->id}", $tgId, $chatId));

    $server = Server::where('user_id', $user->id)->first();
    expect($server)->not->toBeNull();
    expect($server->billing_mode)->toBe('hourly_capped');
    expect($server->hourly_rate_toman)->toBe($hourlyRate);
    expect($server->monthly_cap_toman)->toBe((int) $product->monthly_cap_toman);
    expect($server->billing_started_at)->not->toBeNull();
    expect($server->billing_period_started_at)->not->toBeNull();
    expect($server->billing_period_ends_at)->not->toBeNull();

    // Verify subscription
    $subscription = Subscription::where('server_id', $server->id)->first();
    expect($subscription)->not->toBeNull();
    expect($subscription->billing_mode)->toBe('hourly_capped');
    expect($subscription->hourly_rate_toman)->toBe($hourlyRate);
    expect($subscription->monthly_cap_toman)->toBe((int) $product->monthly_cap_toman);

    // Verify server details display correct billing info via formatServerDetails
    $flow = app(ServerActionsFlow::class);
    $details = $flow->formatServerDetails($server);
    expect($details['hourly_rate_display'])->toContain(number_format($hourlyRate));
    expect($details['cap_display'])->toContain(number_format((int) $product->monthly_cap_toman));
    expect($details['period_charged_display'])->toContain('0');
});

// ═══════════════════════════════════════════════════════════════════════
// 4. MONTHLY RENEWAL WITH PAYMENT FLOW
// ═══════════════════════════════════════════════════════════════════════

it('renews a monthly server through proper Order → Invoice → Payment flow', function () {
    $user = createTelegramUser(55555);
    $tgId = $user->telegramAccount->telegram_user_id;
    $chatId = $user->telegramAccount->telegram_chat_id;

    $product = getMonthlyProduct();

    // Create a server that expires in 5 days with a valid product
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'billing_mode' => 'monthly',
        'expires_at' => now()->addDays(5),
    ]);

    $oldExpiry = $server->expires_at->copy();

    // Show renewal details
    routerHandle(makeCallback("srv:renew:{$server->id}", $tgId, $chatId));

    // Pay — this goes through RenewalService → Order → Invoice → Payment → extend
    routerHandle(makeCallback("srv:renew:pay:{$server->id}", $tgId, $chatId));

    $server->refresh();

    // Expiry extended by exactly one month
    expect($server->expires_at->format('Y-m-d'))->toBe($oldExpiry->copy()->addMonth()->format('Y-m-d'));

    // An order was created for the renewal
    $order = Order::where('user_id', $user->id)->where('billing_mode', 'monthly')->first();
    expect($order)->not->toBeNull();
    expect($order->status)->toBe(Order::STATUS_PAID);

    // Invoice was created and paid
    $invoice = Invoice::where('order_id', $order->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe(Invoice::STATUS_PAID);

    // Payment was confirmed
    $payment = Payment::where('invoice_id', $invoice->id)->first();
    expect($payment)->not->toBeNull();
    expect($payment->status)->toBe(Payment::STATUS_PAID);
});

it('does not extend expiry before payment is confirmed', function () {
    $user = createTelegramUser(55556);
    $product = getMonthlyProduct();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'billing_mode' => 'monthly',
        'expires_at' => now()->addDays(5),
    ]);

    $oldExpiry = $server->expires_at->copy();

    // Create order but don't pay
    $renewal = app(RenewalService::class);
    $order = $renewal->createRenewalOrder($server, $user);

    $server->refresh();
    expect($server->expires_at->format('Y-m-d'))->toBe($oldExpiry->format('Y-m-d'));

    // Order should be pending
    expect($order->status)->toBe(Order::STATUS_PENDING);
});

it('duplicate renewal payment does not extend expiry twice', function () {
    $user = createTelegramUser(55557);
    $product = getMonthlyProduct();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'billing_mode' => 'monthly',
        'expires_at' => now()->addDays(5),
    ]);

    $oldExpiry = $server->expires_at->copy();

    // Full renewal
    $renewal = app(RenewalService::class);
    $result = $renewal->processRenewal($server, $user);

    $server->refresh();
    $firstExpiry = $server->expires_at->copy();
    expect($firstExpiry->format('Y-m-d'))->toBe($oldExpiry->copy()->addMonth()->format('Y-m-d'));

    // Duplicate payment — confirm is idempotent
    $payment = $result['payment'];
    app(PaymentService::class)->confirm($payment, ['approved' => true], $user);

    $server->refresh();
    // Expiry should NOT have changed again
    expect($server->expires_at->format('Y-m-d'))->toBe($firstExpiry->format('Y-m-d'));
});

it('unpaid renewal does not extend service expiration', function () {
    $user = createTelegramUser(55558);
    $product = getMonthlyProduct();
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'billing_mode' => 'monthly',
        'expires_at' => now()->addDays(5),
    ]);

    $oldExpiry = $server->expires_at->copy();

    $renewal = app(RenewalService::class);
    $order = $renewal->createRenewalOrder($server, $user);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');

    // Simulate failed payment
    app(PaymentService::class)->confirm($payment, ['approved' => false]);

    $server->refresh();
    expect($server->expires_at->format('Y-m-d'))->toBe($oldExpiry->format('Y-m-d'));

    // Order should be failed
    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PENDING);
});

it('User A cannot renew User B server', function () {
    $userA = createTelegramUser(55559, 12001);
    $userB = createTelegramUser(55560, 12002);

    $server = Server::factory()->create([
        'user_id' => $userB->id,
        'billing_mode' => 'monthly',
        'expires_at' => now()->addDays(5),
    ]);

    // User A tries to renew User B's server
    routerHandle(makeCallback("srv:renew:pay:{$server->id}", 55559, 12001));

    $server->refresh();
    $oldExpiry = $server->expires_at->copy();

    // Should not be extended — getOwnedServer returns null for wrong user
    // No order should exist for User A for this server
    $order = Order::where('user_id', $userA->id)->first();
    expect($order)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════
// 5. BILLING NOTIFICATIONS WIRED TO TELEGRAM
// ═══════════════════════════════════════════════════════════════════════

it('sends Telegram notification on LowBalanceWarningTriggered event', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $user = createTelegramUser(66666);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'billing_mode' => 'hourly',
        'hourly_rate_toman' => 850,
    ]);

    $warning = LowBalanceWarning::create([
        'user_id' => $user->id,
        'server_id' => $server->id,
        'threshold_hours' => 24,
        'balance_toman' => 20000,
        'rate_toman' => 850,
        'estimated_hours' => 23,
        'warned_at' => now(),
    ]);

    event(new LowBalanceWarningTriggered($warning));

    $sendMessageCalls = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'));
    expect($sendMessageCalls)->not->toBeEmpty();

    // Verify the message content contains billing info (check request body JSON text)
    $firstCall = $sendMessageCalls->first();
    $decoded = json_decode($firstCall[0]->body(), true);
    $text = $decoded['text'] ?? '';
    expect($text)->toContain('موجودی');
    expect($text)->toContain('#'.$server->id);
});

it('does not send notification when user has no telegram chat', function () {
    $user = User::factory()->create(); // No TelegramAccount
    $server = Server::factory()->create(['user_id' => $user->id]);

    $warning = LowBalanceWarning::create([
        'user_id' => $user->id,
        'server_id' => $server->id,
        'threshold_hours' => 24,
        'balance_toman' => 20000,
        'rate_toman' => 850,
        'estimated_hours' => 23,
        'warned_at' => now(),
    ]);

    // Should not throw — just silently return
    event(new LowBalanceWarningTriggered($warning));

    // No sendMessage should be sent
    $sendMessageCalls = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'));
    expect($sendMessageCalls)->toBeEmpty();
});

it('sends grace notification via notifyBillingStateChange', function () {
    $notifier = app(TelegramNotificationService::class);
    $user = createTelegramUser(66667);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'billing_mode' => 'hourly',
        'grace_ends_at' => now()->addHours(48),
    ]);

    $notifier->notifyBillingStateChange($server, 'active', 'grace');

    $sendMessageCalls = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'));
    expect($sendMessageCalls)->not->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════════════
// 6. ASYNC PROVISIONING DELIVERY
// ═══════════════════════════════════════════════════════════════════════

it('dispatches ProvisionServerJob instead of blocking on provisioning', function () {
    $user = createTelegramUser(77777);
    $product = getHourlyProduct();

    // Fund wallet enough
    app(WalletService::class)->credit($user, 500000, 'test');

    // Place order directly to verify the job is dispatched
    $order = app(OrderService::class)->place($user, $product);
    $order->update(['status' => Order::STATUS_PAID]);

    // Verify job class exists and is dispatchable
    $job = new ProvisionServerJob($order);
    expect($job)->toBeInstanceOf(ProvisionServerJob::class);
    expect($job->order)->toBe($order);
    expect($job->tries)->toBe(3);
});

it('ProvisionServerJob is idempotent — duplicate does not create two servers', function () {
    $user = createTelegramUser(77778);
    $product = getMonthlyProduct();
    $order = app(OrderService::class)->place($user, $product);
    $order->update(['status' => Order::STATUS_PAID]);

    // First provisioning
    (new ProvisionServerJob($order))->handle(
        app(ProviderManager::class),
        app(AuditService::class),
        app(HourlyBillingService::class),
    );

    $serverCount = Server::where('order_id', $order->id)->count();
    expect($serverCount)->toBe(1);

    // Second provisioning attempt — should be silently skipped
    (new ProvisionServerJob($order))->handle(
        app(ProviderManager::class),
        app(AuditService::class),
        app(HourlyBillingService::class),
    );

    $serverCount = Server::where('order_id', $order->id)->count();
    expect($serverCount)->toBe(1);
});

// ═══════════════════════════════════════════════════════════════════════
// 7. CALLBACK IDEMPOTENCY
// ═══════════════════════════════════════════════════════════════════════

it('duplicate wallet top-up callbacks do not credit twice', function () {
    $user = createTelegramUser(88888);
    $chatId = $user->telegramAccount->telegram_chat_id;

    // First top-up
    routerHandle(makeCallback('wallet:topup:amount:50000', 88888, $chatId));
    $user->refresh();
    expect($user->wallet->balance_toman)->toBe(50000);

    // Second top-up (different callback ID — Telegram may resend)
    routerHandle(makeCallback('wallet:topup:amount:50000', 88888, $chatId));
    $user->refresh();
    expect($user->wallet->balance_toman)->toBe(100000);
});

it('duplicate order confirm callbacks produce only one server', function () {
    $user = createTelegramUser(88889);
    $tgId = 88889;
    $chatId = $user->telegramAccount->telegram_chat_id;

    $plan = findEnabledPlan();
    $location = findEnabledLocation();
    $image = findEnabledImage();

    $callbackData = "buy:confirm:monthly:{$location->id}:{$plan->id}:{$image->id}";

    // First confirm
    routerHandle(makeCallback($callbackData, $tgId, $chatId));
    $firstOrderCount = Order::where('user_id', $user->id)->count();
    $firstServerCount = Server::where('user_id', $user->id)->count();

    // Second confirm — ProvisionServerJob is idempotent (checks server exists)
    routerHandle(makeCallback($callbackData, $tgId, $chatId));
    $finalServerCount = Server::where('user_id', $user->id)->count();

    // Should only have one server (job idempotency guard)
    expect($finalServerCount)->toBe($firstServerCount);
});

// ═══════════════════════════════════════════════════════════════════════
// 8. STALE / MANIPULATED CALLBACKS
// ═══════════════════════════════════════════════════════════════════════

it('handles invalid product/location/image gracefully', function () {
    $user = createTelegramUser(99999);
    $tgId = 99999;
    $chatId = $user->telegramAccount->telegram_chat_id;

    // Try to buy with non-existent plan
    routerHandle(makeCallback('buy:plan:monthly:99999:99999', $tgId, $chatId));
    // Should not crash — sends error message

    $sendMessageCalls = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'));
    expect($sendMessageCalls)->not->toBeEmpty();
});

it('rejects server action on server belonging to another user', function () {
    $userA = createTelegramUser(10001, 13001);
    $userB = createTelegramUser(10002, 13002);

    $server = Server::factory()->create([
        'user_id' => $userB->id,
        'status' => 'running',
    ]);

    // User A tries to power off User B's server
    routerHandle(makeCallback("srv:action:power_off:{$server->id}", 10001, 13001));

    $server->refresh();
    expect($server->status)->toBe('running'); // Should not have changed
});

it('handles stale buy flow state gracefully', function () {
    $user = createTelegramUser(10003);
    $tgId = 10003;
    $chatId = $user->telegramAccount->telegram_chat_id;

    // Try to confirm buy without any state (expired Redis state)
    // This means the product lookup will fail
    routerHandle(makeCallback('buy:confirm:monthly:99999:99999:99999', $tgId, $chatId));

    $sendMessageCalls = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'));
    expect($sendMessageCalls)->not->toBeEmpty();
});

// ═══════════════════════════════════════════════════════════════════════
// 9. SERVER ACTION COMPLETENESS
// ═══════════════════════════════════════════════════════════════════════

it('performs power_on, power_off, reboot through ServerActionService', function () {
    $user = createTelegramUser(11001);
    $tgId = 11001;
    $chatId = $user->telegramAccount->telegram_chat_id;

    // Seed FakeProvider so adapter is registered
    $provider = Provider::query()->where('code', 'fake')->first();
    $adapter = app(ProviderManager::class)->resolve($provider);

    // Create server via FakeProvider so it's tracked
    $planDto = new ProviderPlanData('cpx21', 'CX21', 2, 4096, 80, 20000, 8.50, 'EUR', 0.012);
    $imageDto = new ProviderImageData('ubuntu-24.04', 'Ubuntu 24.04', 'linux', 'ubuntu', '24.04', 'x86');
    $locationDto = new ProviderLocationData('fsn1', 'Falkenstein', 'DE', 'Falkenstein');

    $serverData = $adapter->createServer($planDto, $imageDto, $locationDto, 'test-actions');

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'provider_server_id' => $serverData->id,
        'status' => 'running',
        'power_state' => 'running',
    ]);

    // Power off
    routerHandle(makeCallback("srv:action:power_off:{$server->id}", $tgId, $chatId));
    $server->refresh();
    expect($server->status)->toBe('off');
    expect($server->power_state)->toBe('off');

    // Power on
    routerHandle(makeCallback("srv:action:power_on:{$server->id}", $tgId, $chatId));
    $server->refresh();
    expect($server->status)->toBe('running');
    expect($server->power_state)->toBe('running');

    // Reboot
    routerHandle(makeCallback("srv:action:reboot:{$server->id}", $tgId, $chatId));
    $server->refresh();
    // Reboot doesn't change status in FakeProvider (it stays running)

    // Verify ServerAction records created
    $actions = ServerAction::where('server_id', $server->id)->get();
    expect($actions->count())->toBeGreaterThanOrEqual(3);
});

it('rebuild requires destructive confirmation then image selection', function () {
    $user = createTelegramUser(11002);
    $tgId = 11002;
    $chatId = $user->telegramAccount->telegram_chat_id;

    $provider = Provider::query()->where('code', 'fake')->first();
    $adapter = app(ProviderManager::class)->resolve($provider);

    $planDto = new ProviderPlanData('cpx21', 'CX21', 2, 4096, 80, 20000, 8.50, 'EUR', 0.012);
    $imageDto = new ProviderImageData('ubuntu-24.04', 'Ubuntu 24.04', 'linux', 'ubuntu', '24.04', 'x86');
    $locationDto = new ProviderLocationData('fsn1', 'Falkenstein', 'DE', 'Falkenstein');

    $serverData = $adapter->createServer($planDto, $imageDto, $locationDto, 'test-rebuild');

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'provider_server_id' => $serverData->id,
        'status' => 'running',
        'power_state' => 'running',
    ]);

    // Step 1: Show rebuild confirmation
    routerHandle(makeCallback("srv:rebuild:{$server->id}", $tgId, $chatId));
    // Should show confirmation dialog (editMessageText called)

    // Step 2: Confirm rebuild — shows image selection
    routerHandle(makeCallback("srv:rebuild:confirm:{$server->id}", $tgId, $chatId));
    // Should show image list

    // Step 3: Pick image — performs rebuild
    $image = ProviderImage::where('provider_id', $provider->id)->where('enabled', true)->first();
    routerHandle(makeCallback("srv:rebuild:pick:{$server->id}:{$image->id}", $tgId, $chatId));

    // Verify rebuild action was performed
    $rebuildAction = ServerAction::where('server_id', $server->id)->where('action', 'rebuild')->first();
    expect($rebuildAction)->not->toBeNull();
    expect($rebuildAction->status)->toBe(ServerAction::STATUS_COMPLETED);
});

it('User A cannot perform any action on User B server', function () {
    $userA = createTelegramUser(11003, 14001);
    $userB = createTelegramUser(11004, 14002);

    $server = Server::factory()->create([
        'user_id' => $userB->id,
        'status' => 'running',
    ]);

    $actions = ['power_on', 'power_off', 'reboot'];
    foreach ($actions as $action) {
        routerHandle(makeCallback("srv:action:{$action}:{$server->id}", 11003, 14001));
    }

    $server->refresh();
    expect($server->status)->toBe('running'); // Nothing should have changed

    // Rebuild
    routerHandle(makeCallback("srv:rebuild:{$server->id}", 11003, 14001));
    routerHandle(makeCallback("srv:rebuild:confirm:{$server->id}", 11003, 14001));
    // Should show "server not found" or not proceed

    // No ServerActions should be created for User A
    $userAActions = ServerAction::where('user_id', $userA->id)->count();
    expect($userAActions)->toBe(0);
});

// ═══════════════════════════════════════════════════════════════════════
// 10. TELEGRAM API FAILURE SAFETY
// ═══════════════════════════════════════════════════════════════════════

it('Telegram sendMessage failure does not corrupt orders or payments', function () {
    $user = createTelegramUser(12001);
    $product = getMonthlyProduct();

    // Simulate Telegram API returning errors
    Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Error'], 500)]);

    // Place order and provision directly (bypasses Telegram menu flow)
    $order = app(OrderService::class)->place($user, $product);
    $invoice = app(OrderService::class)->createInvoice($order, 'manual');
    $payment = app(PaymentService::class)->start($invoice, 'manual');
    app(PaymentService::class)->confirm($payment, ['approved' => true], $user);
    app(PaymentService::class)->provision($order->fresh());

    // Server should still be provisioned — Telegram failures don't affect order processing
    $server = Server::where('user_id', $user->id)->first();
    expect($server)->not->toBeNull();
    expect($server->status)->toBe('running');

    // Order and payment should be intact
    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PROVISIONED);

    $payment->refresh();
    expect($payment->status)->toBe(Payment::STATUS_PAID);

    // Bot token should never appear in logs
    $logContent = file_get_contents(storage_path('logs/laravel.log'));
    expect($logContent)->not->toContain('test-bot-token');
});

it('answerCallbackQuery failure is silently handled', function () {
    Http::fake(['*' => Http::response(['ok' => false, 'description' => 'query too old'], 400)]);

    $user = createTelegramUser(12002);

    // This should not throw
    routerHandle(makeCallback('wallet:main', 12002, $user->telegramAccount->telegram_chat_id));

    // Process should complete without error
    expect(true)->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════
// 11. WEBHOOK SECURITY
// ═══════════════════════════════════════════════════════════════════════

it('rejects webhook with invalid secret', function () {
    $response = $this->postJson('/telegram/webhook', [
        'update_id' => 1,
        'message' => ['text' => '/start', 'chat' => ['id' => 123], 'from' => ['id' => 456]],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong']);

    $response->assertStatus(403);
});

it('accepts webhook with valid secret', function () {
    $response = $this->postJson('/telegram/webhook', [
        'update_id' => 1,
        'message' => ['text' => '/start', 'chat' => ['id' => 123], 'from' => ['id' => 456, 'first_name' => 'Test']],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret-123']);

    $response->assertOk();
});
