<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\SettingKey;
use App\Models\User;
use App\Pricing\Data\PriceQuote;
use App\Pricing\ExchangeRateService;
use App\Pricing\PricingService;
use App\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Tests\Support\Catalog\CatalogBuilder;

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->pricing = app(PricingService::class);
    $this->owner = User::factory()->create();
    $this->owner->assignRole(AdminRole::Owner->value);

    app(SettingsService::class)->set(SettingKey::SalesEnabled, true, $this->owner);
    app(SettingsService::class)->set(SettingKey::FxMaxAgeMinutes, 1_440, $this->owner);

    $this->at = Carbon::parse('2026-09-04 12:00:00');
    $this->effectiveFrom = $this->at->copy()->subMinutes(30);

    $this->catalog = CatalogBuilder::make();
    app(ExchangeRateService::class)->recordManualRate('EUR', '92345.12345678', $this->owner, $this->effectiveFrom);

    $this->quote = $this->pricing->quoteNewSale($this->catalog->price, $this->at);
});

it('holds both timestamps as immutable values', function (): void {
    // `readonly` alone only stops the property being reassigned; a mutable
    // Carbon behind it could still be moved by anyone holding a reference.
    expect($this->quote)->toBeInstanceOf(PriceQuote::class)
        ->and($this->quote->evaluatedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($this->quote->exchangeRateEffectiveFrom)->toBeInstanceOf(CarbonImmutable::class)
        ->and($this->quote->evaluatedAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($this->quote->exchangeRateEffectiveFrom)->toBeInstanceOf(DateTimeImmutable::class);
});

it('returns a new object rather than moving the quote when time is added', function (): void {
    $evaluated = $this->quote->evaluatedAt;
    $effective = $this->quote->exchangeRateEffectiveFrom;

    $laterEvaluated = $this->quote->evaluatedAt->addDay();
    $laterEffective = $this->quote->exchangeRateEffectiveFrom->addMinute();

    expect($laterEvaluated)->not->toBe($evaluated)
        ->and($laterEffective)->not->toBe($effective)
        ->and($this->quote->evaluatedAt->toDateTimeString())->toBe('2026-09-04 12:00:00')
        ->and($this->quote->exchangeRateEffectiveFrom->toDateTimeString())->toBe('2026-09-04 11:30:00');
});

it('keeps toArray stable after attempted temporal manipulation', function (): void {
    $before = $this->quote->toArray();

    $this->quote->evaluatedAt->addDay()->addYear();
    $this->quote->exchangeRateEffectiveFrom->subYear()->addMonths(3);

    expect($this->quote->toArray())->toBe($before);
});

it('keeps evaluated_at at the exact evaluation instant', function (): void {
    expect($this->quote->evaluatedAt->toIso8601String())->toBe($this->at->toIso8601String())
        ->and($this->quote->toArray()['evaluated_at'])->toBe($this->at->toIso8601String());
});

it('keeps exchange_rate_effective_from at the exact selected rate instant', function (): void {
    expect($this->quote->exchangeRateEffectiveFrom->toIso8601String())
        ->toBe($this->effectiveFrom->toIso8601String())
        ->and($this->quote->toArray()['exchange_rate_effective_from'])
        ->toBe($this->effectiveFrom->toIso8601String());
});

it('is unaffected by mutating the model the rate came from', function (): void {
    // The quote must be a snapshot, not a window onto rows that keep changing.
    $rate = App\Models\ExchangeRate::query()->findOrFail($this->quote->exchangeRateId);
    $rate->effective_from->addYear();

    expect($this->quote->exchangeRateEffectiveFrom->toDateTimeString())->toBe('2026-09-04 11:30:00');
});

it('leaves every money value exactly as before', function (): void {
    // The immutability change must not have touched the arithmetic.
    expect($this->quote->providerCost)->toBe('4.550000')
        ->and($this->quote->exchangeRate)->toBe('92345.12345678')
        ->and($this->quote->convertedProviderCostToman)->toBe('420170.31172834900000')
        ->and($this->quote->sellingPriceToman)->toBe(1_500_000)
        ->and($this->quote->grossMarginToman)->toBe('1079829.68827165100000')
        ->and($this->quote->isLossMaking())->toBeFalse();
});
