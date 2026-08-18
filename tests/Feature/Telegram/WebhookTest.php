<?php

use App\Contracts\Data\ProviderImageData;
use App\Contracts\Data\ProviderLocationData;
use App\Contracts\Data\ProviderPlanData;
use App\Models\Provider;
use App\Models\Server;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\ProviderManager;
use App\Services\Telegram\TelegramUpdateRouter;
use App\Services\WalletService;
use Database\Seeders\FakeProviderSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('telegram.bot_token', 'test-bot-token');
    config()->set('telegram.webhook_secret', 'test-secret-123');
    config()->set('telegram.bot_username', 'test_bot');
    config()->set('telegram.api_base_url', 'https://api.telegram.org');
});

it('debug: router sends HTTP on /start', function () {
    Http::fake();

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'message' => [
            'text' => '/start',
            'chat' => ['id' => 99999],
            'from' => ['id' => 88888, 'first_name' => 'DebugUser'],
        ],
    ]);

    $recorded = Http::recorded();
    $this->assertNotEmpty($recorded, 'No HTTP requests recorded at all');

    $urls = $recorded->map(fn ($pair) => $pair[0]->url())->toArray();
    $this->assertNotEmpty(array_filter($urls, fn ($u) => str_contains($u, 'sendMessage')), 'No sendMessage. Got: '.implode(', ', $urls));
});

it('rejects webhook with invalid secret token', function () {
    $response = $this->postJson('/telegram/webhook', [
        'update_id' => 1,
        'message' => ['text' => '/start', 'chat' => ['id' => 123], 'from' => ['id' => 456]],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret']);

    $response->assertStatus(403);
});

it('accepts webhook with valid secret token', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $response = $this->postJson('/telegram/webhook', [
        'update_id' => 1,
        'message' => ['text' => '/start', 'chat' => ['id' => 123], 'from' => ['id' => 456, 'first_name' => 'Test']],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret-123']);

    $response->assertOk();
});

it('accepts webhook with empty body', function () {
    $response = $this->postJson('/telegram/webhook', [], ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret-123']);
    $response->assertOk();
});

it('processes /start and creates user', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'message' => [
            'text' => '/start',
            'chat' => ['id' => 12345],
            'from' => ['id' => 99999, 'first_name' => 'Ali', 'username' => 'ali_test'],
        ],
    ]);

    $this->assertDatabaseHas('users', ['email' => 'tg_99999@telegram.local']);
    $this->assertDatabaseHas('telegram_accounts', [
        'telegram_user_id' => 99999,
        'telegram_chat_id' => 12345,
        'first_name' => 'Ali',
    ]);
});

it('links existing user on subsequent /start', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'message' => [
            'text' => '/start',
            'chat' => ['id' => 12345],
            'from' => ['id' => 99999, 'first_name' => 'Ali'],
        ],
    ]);

    $router->handle([
        'update_id' => 2,
        'message' => [
            'text' => '/start',
            'chat' => ['id' => 12345],
            'from' => ['id' => 99999, 'first_name' => 'Ali Updated'],
        ],
    ]);

    $this->assertDatabaseHas('telegram_accounts', [
        'telegram_user_id' => 99999,
        'first_name' => 'Ali Updated',
    ]);
    $this->assertDatabaseCount('users', 1);
});

it('shows wallet balance', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $user = User::factory()->create();
    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'telegram_user_id' => 77777,
        'telegram_chat_id' => 12345,
    ]);

    app(WalletService::class)->credit($user, 50000, 'test credit');

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cb1',
            'data' => 'wallet:main',
            'chat' => ['id' => 12345],
            'message' => ['message_id' => 10, 'chat' => ['id' => 12345]],
            'from' => ['id' => 77777, 'first_name' => 'Test'],
        ],
    ]);

    $recorded = Http::recorded();
    $sendMessageRequests = array_filter($recorded->toArray(), fn ($pair) => str_contains($pair[0]->url(), 'editMessageText'));
    $this->assertNotEmpty($sendMessageRequests, 'No editMessageText recorded');
});

it('credits wallet via top-up', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $user = User::factory()->create();
    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'telegram_user_id' => 77777,
        'telegram_chat_id' => 12345,
    ]);

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cb1',
            'data' => 'wallet:topup:amount:100000',
            'chat' => ['id' => 12345],
            'message' => ['message_id' => 10, 'chat' => ['id' => 12345]],
            'from' => ['id' => 77777, 'first_name' => 'Test'],
        ],
    ]);

    $user->refresh();
    $this->assertEquals(100000, $user->wallet?->balance_toman);
});

it('rejects server action on non-owned server', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    TelegramAccount::factory()->create([
        'user_id' => $userA->id,
        'telegram_user_id' => 11111,
        'telegram_chat_id' => 12345,
    ]);

    $server = Server::factory()->create([
        'user_id' => $userB->id,
        'status' => 'running',
    ]);

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cb1',
            'data' => "srv:action:power_on:{$server->id}",
            'chat' => ['id' => 12345],
            'message' => ['message_id' => 10, 'chat' => ['id' => 12345]],
            'from' => ['id' => 11111, 'first_name' => 'UserA'],
        ],
    ]);

    $server->refresh();
    $this->assertEquals('running', $server->status);
});

it('lists servers for a user', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $user = User::factory()->create();
    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'telegram_user_id' => 55555,
        'telegram_chat_id' => 12345,
    ]);

    Server::factory()->create([
        'user_id' => $user->id,
        'status' => 'running',
    ]);

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cb1',
            'data' => 'servers:list:0',
            'chat' => ['id' => 12345],
            'message' => ['message_id' => 10, 'chat' => ['id' => 12345]],
            'from' => ['id' => 55555, 'first_name' => 'Test'],
        ],
    ]);

    $recorded = Http::recorded();
    $editRequests = array_filter($recorded->toArray(), fn ($pair) => str_contains($pair[0]->url(), 'editMessageText'));
    $this->assertNotEmpty($editRequests, 'No editMessageText recorded for server list');
});

it('shows server details', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    $user = User::factory()->create();
    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'telegram_user_id' => 55555,
        'telegram_chat_id' => 12345,
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'status' => 'running',
        'ip_address' => '10.0.0.1',
        'billing_mode' => 'monthly',
    ]);

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cb1',
            'data' => "srv:details:{$server->id}",
            'chat' => ['id' => 12345],
            'message' => ['message_id' => 10, 'chat' => ['id' => 12345]],
            'from' => ['id' => 55555, 'first_name' => 'Test'],
        ],
    ]);

    $recorded = Http::recorded();
    $editRequests = array_filter($recorded->toArray(), fn ($pair) => str_contains($pair[0]->url(), 'editMessageText'));
    $this->assertNotEmpty($editRequests, 'No editMessageText for server details');
});

it('performs power action on owned server', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);

    // Seed FakeProvider so the adapter is available and tracks servers
    $this->seed(FakeProviderSeeder::class);

    $user = User::factory()->create();
    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'telegram_user_id' => 55555,
        'telegram_chat_id' => 12345,
    ]);

    // Create server via FakeProvider so it's tracked internally
    $provider = Provider::where('code', 'fake')->first();
    $adapter = app(ProviderManager::class)->resolve($provider);
    $planDto = new ProviderPlanData('cpx21', 'CX21', 2, 4096, 80, 20000, 8.50, 'EUR', 0.012);
    $imageDto = new ProviderImageData('ubuntu-24.04', 'Ubuntu 24.04', 'linux', 'ubuntu', '24.04', 'x86');
    $locationDto = new ProviderLocationData('fsn1', 'Falkenstein', 'DE', 'Falkenstein');

    $serverData = $adapter->createServer($planDto, $imageDto, $locationDto, 'test-power-action');

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'provider_id' => $provider->id,
        'provider_server_id' => $serverData->id,
        'status' => 'running',
        'power_state' => 'running',
    ]);

    $router = app(TelegramUpdateRouter::class);

    $router->handle([
        'update_id' => 1,
        'callback_query' => [
            'id' => 'cb1',
            'data' => "srv:action:power_off:{$server->id}",
            'chat' => ['id' => 12345],
            'message' => ['message_id' => 10, 'chat' => ['id' => 12345]],
            'from' => ['id' => 55555, 'first_name' => 'Test'],
        ],
    ]);

    $server->refresh();
    $this->assertEquals('off', $server->status);
    $this->assertEquals('off', $server->power_state);
});
