<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\ProviderImage;
use App\Orders\Data\ApprovedQuote;
use App\Orders\Data\PurchaseIntent;
use App\Orders\OrderService;
use App\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ForkedWorkers;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * An order may not commit under authorization that was withdrawn while it ran.
 *
 * Every control that decides whether a sale may happen is mutable and lives in
 * PostgreSQL: the sales kill switch, the current terms, the catalog line, the
 * image, the price. They were all read *before* the transaction that inserted
 * the order, so an administrator could switch sales off, replace the terms,
 * withdraw an image or reprice the catalog in the gap — and the order committed
 * anyway, carrying an authorization that no longer existed.
 *
 * Re-reading them inside the transaction is not enough on its own. Under read
 * committed the administrator's commit still lands between our read and our
 * insert, and we would never see it. So the reads take share locks: the
 * administrator's write waits for us, and ours sees whatever they had already
 * committed. Two customers buying at once hold those shared locks together and
 * never wait on each other.
 *
 * Each test below arranges the interleaving explicitly. A second real
 * PostgreSQL session takes the row exclusively, holds it, changes it and
 * commits — so at the instant the purchase begins, the change is real,
 * uncommitted, and invisible to any unlocked read.
 */
function resetSaleAuthorizationTables(): void
{
    DB::statement(
        'TRUNCATE subscriptions, servers, provisioning_attempts, outbox_messages, wallet_transactions,
         invoices, payments, orders, product_location_prices, products, provider_images, provider_plans,
         provider_locations, provider_credentials, providers, exchange_rates, settings, audit_logs,
         fake_provider_servers, fake_provider_actions RESTART IDENTITY CASCADE'
    );
    DB::table('users')->delete();
}

beforeEach(function (): void {
    resetSaleAuthorizationTables();
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open(walletBalance: 20_000_000);
});

afterEach(function (): void {
    resetSaleAuthorizationTables();
});

/**
 * Run one purchase against one concurrent administrative change.
 *
 * Worker 1 owns the row before worker 0 starts and commits its change 1.4
 * seconds later. An unlocked read taken in between sees the old value; a share
 * lock waits and sees the new one.
 *
 * @param  array{sql: string, bindings: list<mixed>, lock: string, lockBindings: list<mixed>}  $change
 * @return array{order: array<string, mixed>, refused: string|null}
 */
function purchaseAgainstChange(ProvisioningFloor $floor, array $change, ?ApprovedQuote $approved = null): array
{
    $priceId = (int) $floor->price->getKey();
    $customerId = (int) $floor->customer->getKey();

    $results = ForkedWorkers::run(2, function (int $index) use ($priceId, $customerId, $change, $approved): array {
        if ($index === 1) {
            // The administrator. Owns the row exclusively before the purchase
            // begins, so nothing can slip past unnoticed, and commits the
            // change while the purchase is still running.
            DB::beginTransaction();
            DB::select($change['lock'], $change['lockBindings']);
            usleep(1_400_000);
            DB::update($change['sql'], $change['bindings']);
            DB::commit();

            return ['role' => 'admin'];
        }

        usleep(300_000);

        $intent = new PurchaseIntent(
            App\Models\User::query()->findOrFail($customerId),
            App\Models\ProductLocationPrice::query()->findOrFail($priceId),
            ProvisioningFloor::AUP_VERSION,
            true,
            'race-'.(string) Str::uuid(),
            approved: $approved,
        );

        try {
            $order = app(OrderService::class)->place($intent);

            return ['role' => 'buyer', 'order_id' => (int) $order->getKey(), 'total' => (int) $order->total_toman];
        } catch (Throwable $exception) {
            return ['role' => 'buyer', 'refused' => $exception::class];
        }
    });

    return ['order' => $results[0], 'refused' => $results[0]['refused'] ?? null];
}

it('commits no order when sales are switched off while the purchase runs', function (): void {
    $outcome = purchaseAgainstChange($this->floor, [
        'lock' => 'SELECT id FROM settings WHERE key = ? FOR UPDATE',
        'lockBindings' => [SettingKey::SalesEnabled->value],
        'sql' => 'UPDATE settings SET value = ? WHERE key = ?',
        'bindings' => ['false', SettingKey::SalesEnabled->value],
    ]);

    expect($outcome['refused'])->not->toBeNull()
        ->and(Order::query()->count())->toBe(0);
});

it('commits no order when the terms are replaced while the purchase runs', function (): void {
    $outcome = purchaseAgainstChange($this->floor, [
        'lock' => 'SELECT id FROM settings WHERE key = ? FOR UPDATE',
        'lockBindings' => [SettingKey::AupCurrentVersion->value],
        'sql' => 'UPDATE settings SET value = ? WHERE key = ?',
        'bindings' => ['2027-06', SettingKey::AupCurrentVersion->value],
    ]);

    // The customer accepted terms the business no longer offers, so there is
    // no consent to record against this order.
    expect($outcome['refused'])->not->toBeNull()
        ->and(Order::query()->count())->toBe(0);
});

it('commits no order when the catalog line is deactivated while the purchase runs', function (): void {
    $outcome = purchaseAgainstChange($this->floor, [
        'lock' => 'SELECT id FROM product_location_prices WHERE id = ? FOR UPDATE',
        'lockBindings' => [$this->floor->price->getKey()],
        'sql' => 'UPDATE product_location_prices SET active = false WHERE id = ?',
        'bindings' => [$this->floor->price->getKey()],
    ]);

    expect($outcome['refused'])->not->toBeNull()
        ->and(Order::query()->count())->toBe(0);
});

it('commits no order when the image is withdrawn while the purchase runs', function (): void {
    $outcome = purchaseAgainstChange($this->floor, [
        'lock' => 'SELECT id FROM provider_images WHERE id = ? FOR UPDATE',
        'lockBindings' => [$this->floor->image->getKey()],
        'sql' => 'UPDATE provider_images SET deprecated = true WHERE id = ?',
        'bindings' => [$this->floor->image->getKey()],
    ]);

    // Building this order would install an operating system the business has
    // withdrawn, on a machine somebody just paid for.
    expect($outcome['refused'])->not->toBeNull()
        ->and(Order::query()->count())->toBe(0);
});

it('commits no order at an approved price the catalog has already left behind', function (): void {
    // Exactly what a preview screen would have captured a moment ago, so the
    // only thing that changes underneath it is the price.
    $quote = app(PricingService::class)->quoteNewSale($this->floor->price);

    $approved = new ApprovedQuote(
        sellingPriceToman: $quote->sellingPriceToman,
        productId: $quote->productId,
        productLocationPriceId: $quote->productLocationPriceId,
        imageSelectionMode: App\Enums\ImageSelectionMode::Default,
        providerImageId: null,
        resolvedProviderImageId: (int) $this->floor->image->getKey(),
        aupVersion: ProvisioningFloor::AUP_VERSION,
        exchangeRateId: $quote->exchangeRateId,
        exchangeRate: $quote->exchangeRate,
    );

    $outcome = purchaseAgainstChange($this->floor, [
        'lock' => 'SELECT id FROM product_location_prices WHERE id = ? FOR UPDATE',
        'lockBindings' => [$this->floor->price->getKey()],
        'sql' => 'UPDATE product_location_prices SET selling_price_toman = ? WHERE id = ?',
        'bindings' => [9_900_000, $this->floor->price->getKey()],
    ], $approved);

    // The customer approved a figure that is no longer the price. Nothing is
    // created and no money moves; the flow shows the new offer and asks again.
    expect($outcome['refused'])->not->toBeNull()
        ->and(Order::query()->count())->toBe(0);
});

it('prices a committed order at the figure that was authoritative when it committed', function (): void {
    // No approved quote, so a repricing is not a refusal — but the order must
    // carry the price that was real at the instant it was created, never the
    // one read before the transaction opened.
    $outcome = purchaseAgainstChange($this->floor, [
        'lock' => 'SELECT id FROM product_location_prices WHERE id = ? FOR UPDATE',
        'lockBindings' => [$this->floor->price->getKey()],
        'sql' => 'UPDATE product_location_prices SET selling_price_toman = ? WHERE id = ?',
        'bindings' => [2_750_000, $this->floor->price->getKey()],
    ]);

    expect($outcome['refused'])->toBeNull()
        ->and(Order::query()->count())->toBe(1)
        ->and((int) Order::query()->sole()->total_toman)->toBe(2_750_000);
});

it('returns an existing order untouched however far the controls have moved', function (): void {
    $key = 'settled-'.(string) Str::uuid();

    $intent = new PurchaseIntent(
        $this->floor->customer,
        $this->floor->price,
        ProvisioningFloor::AUP_VERSION,
        true,
        $key,
    );

    $original = app(OrderService::class)->place($intent);

    // Everything that authorizes a new sale is now different.
    app(App\Settings\SettingsService::class)->set(SettingKey::SalesEnabled, false, $this->floor->owner);
    DB::table('product_location_prices')->where('id', $this->floor->price->getKey())
        ->update(['selling_price_toman' => 9_900_000, 'active' => false]);
    ProviderImage::query()->whereKey($this->floor->image->getKey())->update(['deprecated' => true]);

    // A retry asks "did my purchase work?", and the answer is not affected by
    // any of it. No repricing, no kill-switch decision, no new terms decision.
    $replayed = app(OrderService::class)->place($intent);

    expect($replayed->getKey())->toBe($original->getKey())
        ->and((int) $replayed->total_toman)->toBe((int) $original->total_toman)
        ->and($replayed->aup_version)->toBe($original->aup_version)
        ->and($replayed->status)->toBe(OrderStatus::Pending)
        ->and(Order::query()->count())->toBe(1);
});

it('refuses a sale whose exchange rate was replaced before the purchase began', function (): void {
    // Exchange rates are append-only history, so a new rate is an insert and no
    // row lock can hold it back. What is provable — and what matters — is that
    // a rate committed before the purchase starts is the rate the purchase
    // sees, rather than the one the customer's screen was drawn from.
    //
    // A rate recorded after the order commits is not stale authorization at
    // all: the order's price snapshot is frozen at creation by design, and a
    // later rate does not reach back into it.
    $quote = app(PricingService::class)->quoteNewSale($this->floor->price);

    $approved = new ApprovedQuote(
        sellingPriceToman: $quote->sellingPriceToman,
        productId: $quote->productId,
        productLocationPriceId: $quote->productLocationPriceId,
        imageSelectionMode: App\Enums\ImageSelectionMode::Default,
        providerImageId: null,
        resolvedProviderImageId: (int) $this->floor->image->getKey(),
        aupVersion: ProvisioningFloor::AUP_VERSION,
        exchangeRateId: $quote->exchangeRateId,
        exchangeRate: $quote->exchangeRate,
    );

    app(App\Pricing\ExchangeRateService::class)->recordManualRate('EUR', '112345.99999999', $this->floor->owner);

    $intent = new PurchaseIntent(
        $this->floor->customer,
        $this->floor->price,
        ProvisioningFloor::AUP_VERSION,
        true,
        'fx-'.(string) Str::uuid(),
        approved: $approved,
    );

    expect(fn () => app(OrderService::class)->place($intent))
        ->toThrow(App\Orders\Exceptions\OrderNotPlaceable::class);

    expect(Order::query()->count())->toBe(0);
});
