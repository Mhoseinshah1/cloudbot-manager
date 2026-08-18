<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\ProviderImage;
use App\Models\ProviderLocation;
use App\Models\ProviderPlan;
use App\Models\Server;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\TelegramStateService;
use App\Services\Telegram\TelegramUpdateRouter;
use Database\Seeders\FakeProviderSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('telegram.bot_token', 'test-bot-token');
    config()->set('telegram.webhook_secret', 'test-secret');
    config()->set('telegram.api_base_url', 'https://api.telegram.org');
    config()->set('telegram.state_store', 'array');

    Cache::flush();
    Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
    $this->seed(FakeProviderSeeder::class);
});

function hardeningTelegramUser(int $telegramId = 71001, int $chatId = 72001): User
{
    $user = User::factory()->create();

    TelegramAccount::factory()->create([
        'user_id' => $user->id,
        'telegram_user_id' => $telegramId,
        'telegram_chat_id' => $chatId,
    ]);

    return $user;
}

function hardeningCallback(string $data, int $telegramId, int $chatId, int $updateId): array
{
    return [
        'update_id' => $updateId,
        'callback_query' => [
            'id' => 'cb-'.$updateId,
            'data' => $data,
            'message' => [
                'message_id' => 10,
                'chat' => ['id' => $chatId],
            ],
            'from' => [
                'id' => $telegramId,
                'first_name' => 'Buyer',
            ],
        ],
    ];
}

it('completes a full buy flow and provisions the exact selected location and image', function () {
    $user = hardeningTelegramUser();
    $telegramId = (int) $user->telegramAccount->telegram_user_id;
    $chatId = (int) $user->telegramAccount->telegram_chat_id;

    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();
    $plan = ProviderPlan::query()->findOrFail($product->provider_plan_id);

    // Deliberately choose non-first resources so a fallback-to-first bug is visible.
    $location = ProviderLocation::query()
        ->where('provider_id', $plan->provider_id)
        ->where('enabled', true)
        ->orderBy('id')
        ->skip(1)
        ->firstOrFail();

    $image = ProviderImage::query()
        ->where('provider_id', $plan->provider_id)
        ->where('enabled', true)
        ->whereNull('deprecated')
        ->orderBy('id')
        ->skip(1)
        ->firstOrFail();

    $router = app(TelegramUpdateRouter::class);

    $router->handle(hardeningCallback('buy:start', $telegramId, $chatId, 1));
    $router->handle(hardeningCallback('buy:mode:monthly', $telegramId, $chatId, 2));
    $router->handle(hardeningCallback("buy:loc:monthly:{$location->id}", $telegramId, $chatId, 3));
    $router->handle(hardeningCallback("buy:plan:monthly:{$location->id}:{$plan->id}", $telegramId, $chatId, 4));
    $router->handle(hardeningCallback("buy:img:monthly:{$location->id}:{$plan->id}:{$image->id}", $telegramId, $chatId, 5));
    $router->handle(hardeningCallback("buy:confirm:monthly:{$location->id}:{$plan->id}:{$image->id}", $telegramId, $chatId, 6));

    $order = Order::query()->where('user_id', $user->id)->firstOrFail();
    $server = Server::query()->where('order_id', $order->id)->firstOrFail();

    expect($order->selected_location_id)->toBe($location->id)
        ->and($order->selected_image_id)->toBe($image->id)
        ->and($server->provider_location_id)->toBe($location->id)
        ->and((int) ($server->image_snapshot['id'] ?? 0))->toBe($image->id)
        ->and($server->image_snapshot['provider_image_id'] ?? null)->toBe($image->provider_image_id)
        ->and(Server::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('rejects manipulated location callbacks that have no plan-location availability', function () {
    $user = hardeningTelegramUser(71002, 72002);
    $telegramId = (int) $user->telegramAccount->telegram_user_id;
    $chatId = (int) $user->telegramAccount->telegram_chat_id;

    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();
    $plan = ProviderPlan::query()->findOrFail($product->provider_plan_id);
    $image = ProviderImage::query()
        ->where('provider_id', $plan->provider_id)
        ->where('enabled', true)
        ->whereNull('deprecated')
        ->firstOrFail();

    $unavailableLocation = ProviderLocation::query()->create([
        'provider_id' => $plan->provider_id,
        'provider_location_id' => 'unavailable-test',
        'name' => 'Unavailable Test',
        'country_code' => 'DE',
        'enabled' => true,
    ]);

    app(TelegramStateService::class)->set($telegramId, [
        'flow' => 'buy',
        'billing_mode' => 'monthly',
        'location_id' => $unavailableLocation->id,
        'plan_id' => $plan->id,
        'image_id' => $image->id,
    ]);

    app(TelegramUpdateRouter::class)->handle(hardeningCallback(
        "buy:confirm:monthly:{$unavailableLocation->id}:{$plan->id}:{$image->id}",
        $telegramId,
        $chatId,
        7,
    ));

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Server::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('rejects stale or manipulated previous-step callback data', function () {
    $user = hardeningTelegramUser(71003, 72003);
    $telegramId = (int) $user->telegramAccount->telegram_user_id;
    $chatId = (int) $user->telegramAccount->telegram_chat_id;

    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();
    $plan = ProviderPlan::query()->findOrFail($product->provider_plan_id);
    $locations = ProviderLocation::query()->where('provider_id', $plan->provider_id)->where('enabled', true)->take(2)->get();

    expect($locations)->toHaveCount(2);

    app(TelegramStateService::class)->set($telegramId, [
        'flow' => 'buy',
        'billing_mode' => 'monthly',
        'location_id' => $locations[0]->id,
    ]);

    app(TelegramUpdateRouter::class)->handle(hardeningCallback(
        "buy:plan:monthly:{$locations[1]->id}:{$plan->id}",
        $telegramId,
        $chatId,
        8,
    ));

    $state = app(TelegramStateService::class)->get($telegramId);

    expect($state['location_id'] ?? null)->toBe($locations[0]->id)
        ->and($state)->not->toHaveKey('plan_id');
});

it('makes duplicate confirm callbacks idempotent for the same exact selection', function () {
    $user = hardeningTelegramUser(71004, 72004);
    $telegramId = (int) $user->telegramAccount->telegram_user_id;
    $chatId = (int) $user->telegramAccount->telegram_chat_id;

    $product = Product::query()->where('slug', 'vps-cx21')->firstOrFail();
    $plan = ProviderPlan::query()->findOrFail($product->provider_plan_id);
    $location = ProviderLocation::query()->where('provider_id', $plan->provider_id)->where('enabled', true)->firstOrFail();
    $image = ProviderImage::query()->where('provider_id', $plan->provider_id)->where('enabled', true)->whereNull('deprecated')->firstOrFail();

    app(TelegramStateService::class)->set($telegramId, [
        'flow' => 'buy',
        'billing_mode' => 'monthly',
        'location_id' => $location->id,
        'plan_id' => $plan->id,
        'image_id' => $image->id,
    ]);

    $callback = hardeningCallback(
        "buy:confirm:monthly:{$location->id}:{$plan->id}:{$image->id}",
        $telegramId,
        $chatId,
        9,
    );

    $router = app(TelegramUpdateRouter::class);
    $router->handle($callback);
    $router->handle($callback);

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Server::query()->where('user_id', $user->id)->count())->toBe(1);
});
