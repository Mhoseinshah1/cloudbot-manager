<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\OrderStatus;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\ProductLocationPrice;
use App\Models\User;
use App\Orders\Data\ApprovedQuote;
use App\Orders\Data\PurchaseIntent;
use App\Orders\OrderService;
use App\Pricing\ExchangeRateService;
use App\Pricing\FxAuthorityLock;
use App\Pricing\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ForkedWorkers;
use Tests\Support\Provisioning\ProvisioningFloor;

/**
 * RCH-005. The one sale authority a row lock cannot hold.
 *
 * Every other control a purchase depends on is a row that gets updated, so a
 * share lock on it makes an administrator's write wait. An exchange rate is not
 * updated — it is appended, and `currentRate()` answers with the newest
 * applicable row. A new rate therefore *becomes* the authority the instant it
 * commits, and no lock on the previous row can hold back an INSERT of the next.
 *
 * The window that leaves:
 *
 *   1. a purchase reads rate A;
 *   2. another session inserts and commits applicable rate B;
 *   3. the purchase inserts its order, priced at A;
 *   4. the purchase commits, after B was already current.
 *
 * The order committed under financial authority that had already changed. The
 * earlier test only proved the easy direction — B committed *before* the
 * purchase started, so the purchase saw B — which says nothing about the commit
 * point.
 *
 * Serializable isolation does not close this, and it is worth being explicit:
 * the history above is a legal serial order ("purchase, then rate change"), so
 * PostgreSQL correctly declines to abort it. What is wanted is stronger than
 * serializability and has to be asked for, which is what the advisory
 * readers/writer lock does.
 */
function resetFxAuthorityTables(): void
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
    resetFxAuthorityTables();
    app(RoleProvisioner::class)->sync();

    $this->floor = ProvisioningFloor::open(walletBalance: 20_000_000);
});

afterEach(function (): void {
    resetFxAuthorityTables();
});

it('holds a new rate back until an in-flight purchase has committed', function (): void {
    $priceId = (int) $this->floor->price->getKey();
    $customerId = (int) $this->floor->customer->getKey();
    $ownerId = (int) $this->floor->owner->getKey();

    $results = ForkedWorkers::run(2, function (int $index) use ($priceId, $customerId, $ownerId): array {
        if ($index === 0) {
            // The purchase. It opens its own transaction, takes the currency's
            // authority exactly as OrderService does, prices against rate A, and
            // then holds — the pause standing in for the ordinary work between
            // quoting and inserting.
            return DB::transaction(function () use ($priceId, $customerId): array {
                $price = ProductLocationPrice::query()->findOrFail($priceId);

                app(FxAuthorityLock::class)->shared((string) $price->provider_currency);

                $quote = app(PricingService::class)->quoteNewSale($price);

                usleep(1_800_000);

                $order = Order::query()->create([
                    'user_id' => $customerId,
                    'product_id' => $quote->productId,
                    'product_location_price_id' => $quote->productLocationPriceId,
                    'order_number' => OrderService::newOrderNumber(),
                    'total_toman' => $quote->sellingPriceToman,
                    'idempotency_key' => 'fx-race-'.(string) Str::uuid(),
                    // The frozen figures an order carries, including which rate
                    // it was priced against — the very fact this test is about.
                    'cost_snapshot' => [
                        'provider_id' => $quote->providerId,
                        'provider_cost' => $quote->providerCost,
                        'provider_currency' => $quote->providerCurrency,
                        'exchange_rate_id' => $quote->exchangeRateId,
                        'exchange_rate' => $quote->exchangeRate,
                    ],
                    'pricing_snapshot' => [
                        'selling_price_toman' => $quote->sellingPriceToman,
                    ],
                    'aup_version' => ProvisioningFloor::AUP_VERSION,
                    'aup_accepted_at' => now(),
                ]);

                return [
                    'role' => 'purchase',
                    'rate_id' => $quote->exchangeRateId,
                    'order_id' => (int) $order->getKey(),
                    'committed_at' => microtime(true),
                ];
            });
        }

        // The rate writer, through the real service.
        usleep(600_000);

        $before = microtime(true);

        $rate = app(ExchangeRateService::class)->recordManualRate(
            'EUR', '112345.99999999', User::query()->findOrFail($ownerId),
        );

        return [
            'role' => 'writer',
            'rate_id' => (int) $rate->getKey(),
            'waited_seconds' => microtime(true) - $before,
            'committed_at' => microtime(true),
        ];
    });

    $purchase = $results[0];
    $writer = $results[1];

    expect($purchase['error'])->toBeNull()
        ->and($writer['error'])->toBeNull()
        // The writer genuinely waited: it asked at 0.6s and the purchase held
        // the authority until 1.8s.
        ->and($writer['waited_seconds'])->toBeGreaterThan(0.5)
        // And it committed after the order did, so the order was never priced
        // against authority that had already moved.
        ->and($writer['committed_at'])->toBeGreaterThan($purchase['committed_at'])
        // The order used the rate that was authoritative for its whole life.
        ->and($purchase['rate_id'])->not->toBe($writer['rate_id'])
        ->and(Order::query()->count())->toBe(1)
        ->and(ExchangeRate::query()->count())->toBe(2);
});

it('makes a purchase that starts after a rate change use the new authority', function (): void {
    $priceId = (int) $this->floor->price->getKey();
    $ownerId = (int) $this->floor->owner->getKey();

    $results = ForkedWorkers::run(2, function (int $index) use ($priceId, $ownerId): array {
        if ($index === 1) {
            // The writer goes first and holds the authority through its own
            // work, then commits rate B.
            $rate = app(ExchangeRateService::class)->recordManualRate(
                'EUR', '150000.00000000', User::query()->findOrFail($ownerId),
            );

            return ['role' => 'writer', 'rate_id' => (int) $rate->getKey()];
        }

        usleep(700_000);

        return DB::transaction(function () use ($priceId): array {
            $price = ProductLocationPrice::query()->findOrFail($priceId);

            app(FxAuthorityLock::class)->shared((string) $price->provider_currency);

            $quote = app(PricingService::class)->quoteNewSale($price);

            return ['role' => 'purchase', 'rate_id' => $quote->exchangeRateId, 'rate' => $quote->exchangeRate];
        });
    });

    expect($results[0]['error'])->toBeNull()
        ->and($results[1]['error'])->toBeNull()
        // The purchase priced against B, not against the rate the screen was
        // drawn from.
        ->and($results[0]['rate_id'])->toBe($results[1]['rate_id'])
        ->and($results[0]['rate'])->toBe('150000.00000000');
});

it('refuses an approved quote whose rate the writer has replaced', function (): void {
    // The customer-visible half of the same guarantee: an offer approved
    // against rate A is not silently repriced, it is refused.
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

    app(ExchangeRateService::class)->recordManualRate('EUR', '150000.00000000', $this->floor->owner);

    expect(fn () => app(OrderService::class)->place(new PurchaseIntent(
        $this->floor->customer,
        $this->floor->price,
        ProvisioningFloor::AUP_VERSION,
        true,
        'fx-approved-'.(string) Str::uuid(),
        approved: $approved,
    )))->toThrow(App\Orders\Exceptions\OrderNotPlaceable::class);

    expect(Order::query()->count())->toBe(0);
});

it('does not make one currency wait on another', function (): void {
    // The lock is per currency, so a euro sale and a dollar rate change are
    // independent. A single global FX lock would serialize every sale in the
    // system behind any rate change anywhere.
    expect(FxAuthorityLock::keyFor('EUR'))->not->toBe(FxAuthorityLock::keyFor('USD'))
        ->and(FxAuthorityLock::keyFor('eur'))->toBe(FxAuthorityLock::keyFor('EUR'));

    $ownerId = (int) $this->floor->owner->getKey();

    $results = ForkedWorkers::run(2, function (int $index) use ($ownerId): array {
        if ($index === 0) {
            return DB::transaction(function (): array {
                app(FxAuthorityLock::class)->shared('EUR');
                usleep(1_500_000);

                return ['role' => 'eur-sale'];
            });
        }

        usleep(300_000);
        $before = microtime(true);

        app(ExchangeRateService::class)->recordManualRate(
            'USD', '90000.00000000', User::query()->findOrFail($ownerId),
        );

        return ['role' => 'usd-writer', 'waited_seconds' => microtime(true) - $before];
    });

    expect($results[1]['error'])->toBeNull()
        // It did not queue behind the euro sale.
        ->and($results[1]['waited_seconds'])->toBeLessThan(0.5);
});

it('returns an existing order untouched without taking any FX authority', function (): void {
    // The idempotent path sits above all of this: a retry asks "did my purchase
    // work?", and neither the answer nor the machinery behind it involves
    // today's rate.
    $key = 'fx-idempotent-'.(string) Str::uuid();

    $intent = new PurchaseIntent(
        $this->floor->customer,
        $this->floor->price,
        ProvisioningFloor::AUP_VERSION,
        true,
        $key,
    );

    $original = app(OrderService::class)->place($intent);

    app(ExchangeRateService::class)->recordManualRate('EUR', '150000.00000000', $this->floor->owner);

    $replayed = app(OrderService::class)->place($intent);

    expect($replayed->getKey())->toBe($original->getKey())
        ->and((int) $replayed->total_toman)->toBe((int) $original->total_toman)
        ->and($replayed->status)->toBe(OrderStatus::Pending)
        ->and(Order::query()->count())->toBe(1);
});

it('refuses to take a transaction-scoped authority outside a transaction', function (): void {
    // A `pg_advisory_xact_*` lock taken with no transaction open is acquired
    // and released by the same implicit statement — protection that reads like
    // protection and is not. Refused loudly rather than silently useless.
    expect(fn () => app(FxAuthorityLock::class)->shared('EUR'))->toThrow(LogicException::class);
    expect(fn () => app(FxAuthorityLock::class)->exclusive('EUR'))->toThrow(LogicException::class);

    expect(fn () => FxAuthorityLock::keyFor('EURO'))->toThrow(InvalidArgumentException::class);
});
