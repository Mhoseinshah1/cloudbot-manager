<?php

declare(strict_types=1);

use App\Audit\AuditEvent;
use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\ExchangeRateSource;
use App\Enums\SaleRefusalReason;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Pricing\Exceptions\InvalidExchangeRate;
use App\Pricing\Exceptions\SaleNotAvailable;
use App\Pricing\Exceptions\UnauthorizedRateChange;
use App\Pricing\ExchangeRateService;
use App\Pricing\PricingService;
use App\Settings\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Catalog\CatalogBuilder;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->rates = app(ExchangeRateService::class);
    $this->pricing = app(PricingService::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(AdminRole::Owner->value);

    $this->support = User::factory()->create();
    $this->support->assignRole(AdminRole::Support->value);

    $this->finance = User::factory()->create();
    $this->finance->assignRole(AdminRole::Finance->value);
});

/** A catalog priced in one currency, with sales enabled and a freshness limit set. */
function pricedCatalog(string $currency = 'EUR', int $maxAgeMinutes = 120): CatalogBuilder
{
    app(SettingsService::class)->set(SettingKey::SalesEnabled, true, test()->owner);
    app(SettingsService::class)->set(SettingKey::FxMaxAgeMinutes, $maxAgeMinutes, test()->owner);

    return CatalogBuilder::make($currency);
}

function fxRefusalFor(CatalogBuilder $catalog): SaleRefusalReason
{
    try {
        test()->pricing->quoteNewSale($catalog->price);
    } catch (SaleNotAvailable $refusal) {
        return $refusal->reason;
    }

    test()->fail('The sale was quoted when it should have been refused.');
}

it('lets an owner record a manual rate', function (): void {
    $rate = $this->rates->recordManualRate('eur', '92345.12345678', $this->owner);

    expect($rate->currency)->toBe('EUR')
        ->and($rate->source)->toBe(ExchangeRateSource::Manual)
        ->and($rate->created_by_admin_id)->toBe($this->owner->id);
});

it('refuses a rate from support', function (): void {
    // Support handles customers and servers. The rate decides margin on every
    // sale, and that is policy.
    expect(fn () => $this->rates->recordManualRate('EUR', '90000', $this->support))
        ->toThrow(UnauthorizedRateChange::class);

    expect(ExchangeRate::query()->count())->toBe(0);
});

it('refuses a rate from finance', function (): void {
    // Finance moves money that already exists; it does not set business policy.
    expect(fn () => $this->rates->recordManualRate('EUR', '90000', $this->finance))
        ->toThrow(UnauthorizedRateChange::class);

    expect(ExchangeRate::query()->count())->toBe(0);
});

it('refuses a rate from an ordinary customer', function (): void {
    expect(fn () => $this->rates->recordManualRate('EUR', '90000', User::factory()->fromTelegram()->create()))
        ->toThrow(UnauthorizedRateChange::class);
});

it('refuses a rate from a suspended or banned administrator', function (): void {
    foreach ([UserStatus::Suspended, UserStatus::Banned] as $status) {
        $admin = User::factory()->create(['status' => $status]);
        $admin->assignRole(AdminRole::Owner->value);

        expect(fn () => $this->rates->recordManualRate('EUR', '90000', $admin))
            ->toThrow(UnauthorizedRateChange::class);
    }

    expect(ExchangeRate::query()->count())->toBe(0);
});

it('stores a decimal rate exactly', function (): void {
    // NUMERIC(20,8) to eight places. A float would not survive this.
    $rate = $this->rates->recordManualRate('EUR', '92345.12345678', $this->owner);

    expect($rate->fresh()->rate_to_toman)->toBe('92345.12345678')
        ->and(DB::table('exchange_rates')->where('id', $rate->id)->value('rate_to_toman'))
        ->toBe('92345.12345678');
});

it('rejects a zero or negative rate', function (): void {
    foreach (['0', '0.00000000', '-1', '-0.00000001'] as $bad) {
        expect(fn () => $this->rates->recordManualRate('EUR', $bad, $this->owner))
            ->toThrow(InvalidExchangeRate::class);
    }

    expect(ExchangeRate::query()->count())->toBe(0);
});

it('rejects a non-positive rate in the database too', function (): void {
    expect(fn () => DB::transaction(fn () => ExchangeRate::query()->create([
        'currency' => 'EUR',
        'rate_to_toman' => '0',
        'source' => ExchangeRateSource::Manual,
        'effective_from' => now(),
    ])))->toThrow(QueryException::class);
});

it('rejects a currency that is not three letters', function (): void {
    foreach (['EU', 'EUROS', '12', ''] as $bad) {
        expect(fn () => $this->rates->recordManualRate($bad, '90000', $this->owner))
            ->toThrow(InvalidExchangeRate::class);
    }
});

it('chooses the newest rate already in effect', function (): void {
    $now = Carbon::parse('2026-09-04 12:00:00');

    $this->rates->recordManualRate('EUR', '80000', $this->owner, $now->copy()->subDays(2));
    $wanted = $this->rates->recordManualRate('EUR', '90000', $this->owner, $now->copy()->subHour());
    $this->rates->recordManualRate('EUR', '70000', $this->owner, $now->copy()->subDays(5));

    expect($this->rates->currentRate('EUR', $now)->getKey())->toBe($wanted->getKey());
});

it('ignores a rate dated in the future until it takes effect', function (): void {
    // A scheduled change is stored now and must not start pricing sales early.
    $now = Carbon::parse('2026-09-04 12:00:00');

    $current = $this->rates->recordManualRate('EUR', '90000', $this->owner, $now->copy()->subHour());
    $future = $this->rates->recordManualRate('EUR', '95000', $this->owner, $now->copy()->addHour());

    expect($this->rates->currentRate('EUR', $now)->getKey())->toBe($current->getKey())
        ->and($this->rates->currentRate('EUR', $now->copy()->addHour())->getKey())->toBe($future->getKey());
});

it('breaks a tie on the same effective moment with the later row', function (): void {
    // An operator correcting a rate they just entered means the correction.
    $at = Carbon::parse('2026-09-04 09:00:00');

    $this->rates->recordManualRate('EUR', '90000', $this->owner, $at);
    $correction = $this->rates->recordManualRate('EUR', '91000', $this->owner, $at);

    expect($this->rates->currentRate('EUR', $at)->getKey())->toBe($correction->getKey());
});

it('leaves earlier rates untouched when a new one is recorded', function (): void {
    // The table is a history. An order priced last week was priced at last
    // week's rate, and rewriting the row would rewrite what the customer paid.
    $old = $this->rates->recordManualRate('EUR', '80000', $this->owner, now()->subDay());
    $snapshot = DB::table('exchange_rates')->where('id', $old->id)->first();

    $this->rates->recordManualRate('EUR', '99000', $this->owner);

    expect((array) DB::table('exchange_rates')->where('id', $old->id)->first())
        ->toBe((array) $snapshot)
        ->and(ExchangeRate::query()->where('currency', 'EUR')->count())->toBe(2);
});

it('blocks a new sale when no rate applies to the currency', function (): void {
    $catalog = pricedCatalog('EUR');

    expect(fxRefusalFor($catalog))->toBe(SaleRefusalReason::MissingFxRate);
});

it('blocks a new sale on a stale rate', function (): void {
    $catalog = pricedCatalog('EUR', maxAgeMinutes: 60);
    $this->rates->recordManualRate('EUR', '90000', $this->owner, now()->subMinutes(61));

    expect(fxRefusalFor($catalog))->toBe(SaleRefusalReason::StaleFxRate);
});

it('allows a new sale on a fresh rate', function (): void {
    $catalog = pricedCatalog('EUR', maxAgeMinutes: 60);
    $this->rates->recordManualRate('EUR', '90000', $this->owner, now()->subMinutes(5));

    expect($this->pricing->quoteNewSale($catalog->price)->exchangeRate)->toBe('90000.00000000');
});

it('treats a rate exactly at the freshness limit as fresh', function (): void {
    // At the limit is within it. Stated once here and once in the service, so
    // the boundary cannot drift by an off-by-one.
    $at = Carbon::parse('2026-09-04 12:00:00');
    $catalog = pricedCatalog('EUR', maxAgeMinutes: 60);

    $rate = $this->rates->recordManualRate('EUR', '90000', $this->owner, $at->copy()->subMinutes(60));

    expect($this->pricing->quoteNewSale($catalog->price, $at)->exchangeRateId)->toBe($rate->id);
});

it('treats a rate one minute past the freshness limit as stale', function (): void {
    $at = Carbon::parse('2026-09-04 12:00:00');
    $catalog = pricedCatalog('EUR', maxAgeMinutes: 60);

    $this->rates->recordManualRate('EUR', '90000', $this->owner, $at->copy()->subMinutes(61));

    try {
        $this->pricing->quoteNewSale($catalog->price, $at);
        $this->fail('A rate one minute past the limit was accepted.');
    } catch (SaleNotAvailable $refusal) {
        expect($refusal->reason)->toBe(SaleRefusalReason::StaleFxRate);
    }
});

it('keeps one currency going stale from affecting another', function (): void {
    $eur = pricedCatalog('EUR', maxAgeMinutes: 60);
    $usd = CatalogBuilder::make('USD', '5.000000');

    $this->rates->recordManualRate('EUR', '90000', $this->owner, now()->subMinutes(600));
    $this->rates->recordManualRate('USD', '85000', $this->owner, now()->subMinutes(5));

    expect(fxRefusalFor($eur))->toBe(SaleRefusalReason::StaleFxRate)
        ->and($this->pricing->quoteNewSale($usd->price)->providerCurrency)->toBe('USD');
});

it('does not change a selling price when the rate changes', function (): void {
    $catalog = pricedCatalog('EUR', maxAgeMinutes: 600);
    $this->rates->recordManualRate('EUR', '90000', $this->owner, now()->subMinute());

    $before = $this->pricing->quoteNewSale($catalog->price)->sellingPriceToman;

    $this->rates->recordManualRate('EUR', '99000', $this->owner);

    $priceRow = $catalog->price->fresh();

    expect($priceRow->selling_price_toman)->toBe($before)
        ->and($this->pricing->quoteNewSale($priceRow)->sellingPriceToman)->toBe($before)
        // Only the margin moves, because only the cost side did.
        ->and($this->pricing->quoteNewSale($priceRow)->exchangeRate)->toBe('99000.00000000');
});

it('audits a recorded rate with safe metadata', function (): void {
    $rate = $this->rates->recordManualRate('EUR', '92345.12345678', $this->owner);

    $entry = AuditLog::query()->where('event', AuditEvent::ExchangeRateRecorded)->sole();

    expect($entry->metadata['exchange_rate_id'])->toBe($rate->id)
        ->and($entry->metadata['currency'])->toBe('EUR')
        ->and($entry->metadata['rate_to_toman'])->toBe('92345.12345678')
        ->and($entry->metadata['effective_from'])->not->toBeNull()
        ->and($entry->actor_id)->toBe((string) $this->owner->id);
});

it('makes no external request when recording or reading a rate', function (): void {
    // Release 1.0 FX is administrator-managed. There is no feed to call.
    Http::preventStrayRequests();

    $this->rates->recordManualRate('EUR', '90000', $this->owner);
    $this->rates->currentRate('EUR');

    Http::assertNothingSent();
});
