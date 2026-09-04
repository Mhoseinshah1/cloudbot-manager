<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Enums\OrderRefusalReason;
use App\Enums\OrderStatus;
use App\Enums\SaleRefusalReason;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\ProviderImage;
use App\Models\User;
use App\Orders\Data\PurchaseIntent;
use App\Orders\Exceptions\OrderIdempotencyConflict;
use App\Orders\Exceptions\OrderNotPlaceable;
use App\Orders\OrderService;
use App\Pricing\Exceptions\SaleNotAvailable;
use App\Pricing\ExchangeRateService;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\Orders\SalesFloor;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->orders = app(OrderService::class);
    $this->floor = SalesFloor::open();
});

/** A purchase intent for the open sales floor, with one thing optionally varied. */
function intentFor(array $overrides = []): PurchaseIntent
{
    $floor = test()->floor;

    return new PurchaseIntent(
        user: $overrides['user'] ?? $floor->customer,
        locationPrice: $overrides['locationPrice'] ?? $floor->catalog->price,
        acceptedAupVersion: $overrides['acceptedAupVersion'] ?? SalesFloor::AUP_VERSION,
        aupAccepted: $overrides['aupAccepted'] ?? true,
        idempotencyKey: $overrides['idempotencyKey'] ?? (string) Str::uuid(),
        providerImageId: $overrides['providerImageId'] ?? null,
    );
}

function refusalToPlace(PurchaseIntent $intent): OrderRefusalReason
{
    try {
        test()->orders->place($intent);
    } catch (OrderNotPlaceable $refusal) {
        return $refusal->reason;
    }

    test()->fail('The order was placed when it should have been refused.');
}

it('places one order for an active customer who accepted the current terms', function (): void {
    $order = $this->orders->place(intentFor());

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->user_id)->toBe($this->floor->customer->id)
        ->and($order->product_id)->toBe($this->floor->catalog->product->id)
        ->and($order->product_location_price_id)->toBe($this->floor->catalog->price->id)
        ->and($order->total_toman)->toBe(1_500_000)
        ->and($order->aup_version)->toBe(SalesFloor::AUP_VERSION)
        ->and($order->attempts)->toBe(0)
        ->and($order->provisioning_uuid)->toBeNull()
        ->and(Order::query()->count())->toBe(1);
});

it('refuses a suspended customer', function (): void {
    $suspended = User::factory()->fromTelegram()->create(['status' => UserStatus::Suspended]);

    expect(refusalToPlace(intentFor(['user' => $suspended])))->toBe(OrderRefusalReason::InactiveCustomer);
    expect(Order::query()->count())->toBe(0);
});

it('refuses a banned customer', function (): void {
    $banned = User::factory()->fromTelegram()->create(['status' => UserStatus::Banned]);

    expect(refusalToPlace(intentFor(['user' => $banned])))->toBe(OrderRefusalReason::InactiveCustomer);
});

it('refuses a customer who did not accept the terms', function (): void {
    expect(refusalToPlace(intentFor(['aupAccepted' => false])))->toBe(OrderRefusalReason::TermsNotAccepted);
    expect(Order::query()->count())->toBe(0);
});

it('refuses a customer who accepted a stale terms version', function (): void {
    // Agreeing to last month's terms is agreeing to something the business no
    // longer offers.
    expect(refusalToPlace(intentFor(['acceptedAupVersion' => '2025-06'])))
        ->toBe(OrderRefusalReason::TermsVersionMismatch);
});

it('refuses to sell when no terms version is configured', function (): void {
    // Nobody can accept terms that have not been declared.
    app(SettingsService::class)->set(SettingKey::AupCurrentVersion, null, $this->floor->owner);

    expect(refusalToPlace(intentFor()))->toBe(OrderRefusalReason::TermsNotConfigured);
});

it('sets the acceptance time on the server', function (): void {
    // A customer-supplied timestamp proves nothing about when they accepted.
    $before = now()->subSecond();
    $order = $this->orders->place(intentFor());

    expect($order->aup_accepted_at)->not->toBeNull()
        ->and($order->aup_accepted_at->greaterThanOrEqualTo($before))->toBeTrue()
        ->and($order->aup_accepted_at->lessThanOrEqualTo(now()->addSecond()))->toBeTrue();
});

it('re-runs the pricing checks on every first creation', function (): void {
    // An order must not be placeable against a price that was valid earlier.
    app(SettingsService::class)->set(SettingKey::SalesEnabled, false, $this->floor->owner);

    try {
        $this->orders->place(intentFor());
        $this->fail('An order was placed with sales disabled.');
    } catch (SaleNotAvailable $refusal) {
        expect($refusal->reason)->toBe(SaleRefusalReason::SalesDisabled);
    }

    expect(Order::query()->count())->toBe(0);
});

it('refuses to place an order on a stale exchange rate', function (): void {
    // Time passing, expressed as the rate ageing: every applicable rate is now
    // older than the threshold, so there is no fresh one to fall back to.
    app(SettingsService::class)->set(SettingKey::FxMaxAgeMinutes, 60, $this->floor->owner);
    DB::table('exchange_rates')->update(['effective_from' => now()->subHours(3)]);

    try {
        $this->orders->place(intentFor());
        $this->fail('An order was placed on a stale rate.');
    } catch (SaleNotAvailable $refusal) {
        expect($refusal->reason)->toBe(SaleRefusalReason::StaleFxRate);
    }
});

it('snapshots exactly what the quote said', function (): void {
    $order = $this->orders->place(intentFor());

    expect($order->cost_snapshot['provider_cost'])->toBe('4.550000')
        ->and($order->cost_snapshot['provider_currency'])->toBe('EUR')
        ->and($order->cost_snapshot['exchange_rate'])->toBe('92345.12345678')
        ->and($order->cost_snapshot['converted_provider_cost_toman'])->toBe('420170.31172834900000')
        ->and($order->cost_snapshot['gross_margin_toman'])->toBe('1079829.68827165100000')
        ->and($order->cost_snapshot['provider_code'])->toBe($this->floor->catalog->provider->code)
        ->and($order->cost_snapshot['provider_plan_code'])->toBe($this->floor->catalog->plan->provider_plan_id)
        ->and($order->cost_snapshot['provider_location_code'])
        ->toBe($this->floor->catalog->location->provider_location_id)
        ->and($order->pricing_snapshot['selling_price_toman'])->toBe(1_500_000)
        ->and($order->pricing_snapshot['billing_mode'])->toBe('monthly')
        ->and($order->pricing_snapshot['billing_cycle'])->toBe('monthly');
});

it('charges exactly the configured selling price', function (): void {
    $this->floor->catalog->price->forceFill(['selling_price_toman' => 987_654])->save();

    $order = $this->orders->place(intentFor());

    expect($order->total_toman)->toBe(987_654)
        ->and($order->pricing_snapshot['selling_price_toman'])->toBe(987_654);
});

it('keeps every money value in the snapshots out of float', function (): void {
    $order = $this->orders->place(intentFor());

    foreach ([$order->cost_snapshot, $order->pricing_snapshot] as $snapshot) {
        array_walk_recursive($snapshot, function (mixed $value, string|int $key): void {
            expect(is_float($value))->toBeFalse("{$key} is a float");
        });
    }

    expect($order->total_toman)->toBeInt()
        ->and($order->cost_snapshot['provider_cost'])->toBeString()
        ->and($order->cost_snapshot['gross_margin_toman'])->toBeString();
});

it('snapshots the selected image identity', function (): void {
    // Phase 7 builds from this rather than from whatever a Telegram
    // conversation still remembers.
    $order = $this->orders->place(intentFor(['providerImageId' => $this->floor->catalog->image->id]));

    $image = $order->pricing_snapshot['image'];

    expect($image['provider_image_id'])->toBe($this->floor->catalog->image->id)
        ->and($image['provider_native_id'])->toBe($this->floor->catalog->image->provider_image_id)
        ->and($image['os_family'])->toBe('ubuntu')
        ->and($image['version'])->toBe('24.04')
        ->and($image['architecture'])->toBe('x86')
        ->and(array_keys($image))->toBe([
            'provider_image_id', 'provider_native_id', 'name', 'os_family', 'version', 'architecture',
        ]);
});

it('falls back to the location default image', function (): void {
    $order = $this->orders->place(intentFor());

    expect($order->pricing_snapshot['image']['provider_image_id'])->toBe($this->floor->catalog->image->id);
});

it('refuses an order with no image and no default', function (): void {
    $this->floor->catalog->price->forceFill(['default_image_id' => null])->save();

    expect(refusalToPlace(intentFor()))->toBe(OrderRefusalReason::NoSelectableImage);
});

it('refuses an image belonging to another provider', function (): void {
    $other = $this->floor->catalog->foreignProvider();

    expect(refusalToPlace(intentFor(['providerImageId' => $other->image->id])))
        ->toBe(OrderRefusalReason::NoSelectableImage);
});

it('refuses a disabled or deprecated image', function (): void {
    foreach ([['enabled' => false], ['deprecated' => true]] as $state) {
        $image = ProviderImage::query()->create([
            'provider_id' => $this->floor->catalog->provider->id,
            'provider_image_id' => 'img-'.bin2hex(random_bytes(3)),
            'name' => 'Retired', 'os_family' => 'debian', 'version' => '11', 'architecture' => 'x86',
            ...$state,
        ]);

        expect(refusalToPlace(intentFor(['providerImageId' => $image->id])))
            ->toBe(OrderRefusalReason::NoSelectableImage);
    }
});

it('returns the same order for a replayed key', function (): void {
    $key = (string) Str::uuid();

    $first = $this->orders->place(intentFor(['idempotencyKey' => $key]));
    $second = $this->orders->place(intentFor(['idempotencyKey' => $key]));

    expect($second->getKey())->toBe($first->getKey())
        ->and($second->order_number)->toBe($first->order_number)
        ->and(Order::query()->count())->toBe(1);
});

it('does not reprice a replay after the exchange rate moved', function (): void {
    // The retry means "I am not sure my first request arrived". The answer is
    // the order that exists, not a fresh business decision at today's rate.
    $key = (string) Str::uuid();
    $first = $this->orders->place(intentFor(['idempotencyKey' => $key]));

    app(ExchangeRateService::class)->recordManualRate('EUR', '150000.00000000', $this->floor->owner);

    $replay = $this->orders->place(intentFor(['idempotencyKey' => $key]));

    expect($replay->getKey())->toBe($first->getKey())
        ->and($replay->cost_snapshot['exchange_rate'])->toBe('92345.12345678')
        ->and($replay->total_toman)->toBe($first->total_toman);
});

it('does not reprice a replay after the selling price changed', function (): void {
    $key = (string) Str::uuid();
    $first = $this->orders->place(intentFor(['idempotencyKey' => $key]));

    $this->floor->catalog->price->forceFill(['selling_price_toman' => 9_999_999])->save();

    expect($this->orders->place(intentFor(['idempotencyKey' => $key]))->total_toman)
        ->toBe($first->total_toman)->toBe(1_500_000);
});

it('returns the existing order even after sales are switched off', function (): void {
    // The purchase already happened. Refusing the retry would tell a customer
    // their completed order does not exist.
    $key = (string) Str::uuid();
    $first = $this->orders->place(intentFor(['idempotencyKey' => $key]));

    app(SettingsService::class)->set(SettingKey::SalesEnabled, false, $this->floor->owner);

    expect($this->orders->place(intentFor(['idempotencyKey' => $key]))->getKey())->toBe($first->getKey());
});

it('returns the existing order even after the terms version moved on', function (): void {
    $key = (string) Str::uuid();
    $first = $this->orders->place(intentFor(['idempotencyKey' => $key]));

    app(SettingsService::class)->set(SettingKey::AupCurrentVersion, '2027-01', $this->floor->owner);

    $replay = $this->orders->place(intentFor(['idempotencyKey' => $key]));

    expect($replay->getKey())->toBe($first->getKey())
        ->and($replay->aup_version)->toBe(SalesFloor::AUP_VERSION);
});

it('refuses a replayed key from a different customer', function (): void {
    $key = (string) Str::uuid();
    $this->orders->place(intentFor(['idempotencyKey' => $key]));

    $stranger = User::factory()->fromTelegram()->create();

    expect(fn () => $this->orders->place(intentFor(['idempotencyKey' => $key, 'user' => $stranger])))
        ->toThrow(OrderIdempotencyConflict::class);

    expect(Order::query()->count())->toBe(1);
});

it('refuses a replayed key for a different product or location', function (): void {
    $key = (string) Str::uuid();
    $this->orders->place(intentFor(['idempotencyKey' => $key]));

    $otherCatalog = Tests\Support\Catalog\CatalogBuilder::make();

    expect(fn () => $this->orders->place(intentFor([
        'idempotencyKey' => $key, 'locationPrice' => $otherCatalog->price,
    ])))->toThrow(OrderIdempotencyConflict::class);
});

it('refuses a replayed key with a different selected image', function (): void {
    $key = (string) Str::uuid();
    $this->orders->place(intentFor(['idempotencyKey' => $key]));

    $another = ProviderImage::query()->create([
        'provider_id' => $this->floor->catalog->provider->id,
        'provider_image_id' => 'debian-12', 'name' => 'Debian 12',
        'os_family' => 'debian', 'version' => '12', 'architecture' => 'x86',
    ]);

    expect(fn () => $this->orders->place(intentFor([
        'idempotencyKey' => $key, 'providerImageId' => $another->id,
    ])))->toThrow(OrderIdempotencyConflict::class);
});

it('refuses a replayed key with a different accepted terms version', function (): void {
    $key = (string) Str::uuid();
    $this->orders->place(intentFor(['idempotencyKey' => $key]));

    expect(fn () => $this->orders->place(intentFor([
        'idempotencyKey' => $key, 'acceptedAupVersion' => '2027-01',
    ])))->toThrow(OrderIdempotencyConflict::class);
});

it('gives every order a distinct number', function (): void {
    $numbers = collect(range(1, 5))
        ->map(fn (): string => $this->orders->place(intentFor())->order_number);

    expect($numbers->unique())->toHaveCount(5)
        ->and($numbers->first())->toStartWith('ORD-');
});

it('refuses a duplicate order number in the database', function (): void {
    $order = $this->orders->place(intentFor());

    expect(fn () => DB::transaction(fn () => DB::table('orders')->insert([
        'user_id' => $order->user_id, 'product_id' => $order->product_id,
        'product_location_price_id' => $order->product_location_price_id,
        'order_number' => $order->order_number, 'status' => 'pending', 'total_toman' => 1,
        'idempotency_key' => 'other', 'cost_snapshot' => '{}', 'pricing_snapshot' => '{}',
        'aup_version' => 'x', 'aup_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(Illuminate\Database\QueryException::class);
});

it('audits creation once and not again on replay', function (): void {
    $key = (string) Str::uuid();

    $this->orders->place(intentFor(['idempotencyKey' => $key]));
    $this->orders->place(intentFor(['idempotencyKey' => $key]));

    expect(AuditLog::query()->where('event', AuditEvent::OrderCreated)->count())->toBe(1);
});

it('makes no network request while placing an order', function (): void {
    Http::preventStrayRequests();

    $this->orders->place(intentFor());

    Http::assertNothingSent();
});
