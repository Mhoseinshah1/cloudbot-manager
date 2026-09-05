<?php

declare(strict_types=1);

use App\Authorization\RoleProvisioner;
use App\Enums\AdminRole;
use App\Enums\SaleRefusalReason;
use App\Enums\SettingKey;
use App\Models\User;
use App\Pricing\Exceptions\SaleNotAvailable;
use App\Pricing\ExchangeRateService;
use App\Pricing\PricingService;
use App\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Support\Catalog\CatalogBuilder;

/** A fixed instant, so nothing here depends on when the suite runs. */
const EVALUATED_AT = '2026-09-04 12:00:00';

beforeEach(function (): void {
    app(RoleProvisioner::class)->sync();

    $this->pricing = app(PricingService::class);
    $this->rates = app(ExchangeRateService::class);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(AdminRole::Owner->value);

    app(SettingsService::class)->set(SettingKey::SalesEnabled, true, $this->owner);
    app(SettingsService::class)->set(SettingKey::FxMaxAgeMinutes, 60, $this->owner);

    $this->at = Carbon::parse(EVALUATED_AT);
    $this->catalog = CatalogBuilder::make();
});

/**
 * Record a EUR rate whose age at the evaluation instant is exactly as given,
 * then say whether a sale would be refused as stale.
 */
function staleAt(int $minutes, int $seconds = 0): bool
{
    $effectiveFrom = test()->at->copy()->subMinutes($minutes)->subSeconds($seconds);
    test()->rates->recordManualRate('EUR', '90000', test()->owner, $effectiveFrom);

    try {
        test()->pricing->quoteNewSale(test()->catalog->price, test()->at);
    } catch (SaleNotAvailable $refusal) {
        expect($refusal->reason)->toBe(SaleRefusalReason::StaleFxRate);

        return true;
    }

    return false;
}

it('treats a rate 59 minutes 59 seconds old as fresh', function (): void {
    expect(staleAt(59, 59))->toBeFalse();
});

it('treats a rate exactly 60 minutes old as fresh', function (): void {
    // At the limit is within it.
    expect(staleAt(60, 0))->toBeFalse();
});

it('treats a rate 60 minutes and one second old as stale', function (): void {
    // The case the old implementation got wrong: it truncated the age to a
    // whole 60 minutes and kept selling for another 59 seconds on a rate that
    // had already expired.
    expect(staleAt(60, 1))->toBeTrue();
});

it('treats a rate 60 minutes 59 seconds old as stale', function (): void {
    expect(staleAt(60, 59))->toBeTrue();
});

it('treats a rate 61 minutes old as stale', function (): void {
    expect(staleAt(61, 0))->toBeTrue();
});

it('decides staleness from the instants, not a rounded age', function (): void {
    // Directly against the service, so the boundary is pinned independently of
    // the pricing path. ageInMinutes reports 60 for all three; only the
    // instant comparison separates them.
    $rate = $this->rates->recordManualRate(
        'EUR', '90000', $this->owner, $this->at->copy()->subMinutes(60)->subSecond(),
    );

    expect($this->rates->ageInMinutes($rate, $this->at))->toBe(60)
        ->and($this->rates->isStale($rate, 60, $this->at))->toBeTrue()
        ->and($this->rates->isStale($rate, 61, $this->at))->toBeFalse();
});

it('warns with safe operational detail when a stale rate blocks a sale', function (): void {
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = ['level' => $message->level, 'message' => $message->message, 'context' => $message->context];
    });

    $rate = $this->rates->recordManualRate(
        'EUR', '90000', $this->owner, $this->at->copy()->subMinutes(90),
    );

    expect(staleAt(90))->toBeTrue();

    $warnings = array_values(array_filter(
        $logged,
        fn (array $entry): bool => $entry['message'] === 'pricing.fx_rate_stale',
    ));

    expect($warnings)->not->toBeEmpty();

    $context = $warnings[0]['context'];

    expect($warnings[0]['level'])->toBe('warning')
        ->and($context['currency'])->toBe('EUR')
        ->and($context['exchange_rate_id'])->toBeInt()
        ->and($context['threshold_minutes'])->toBe(60)
        ->and($context['effective_from'])->toBeString()
        ->and($context['evaluated_at'])->toBeString()
        // Identifiers and times only. No model dump, no metadata bag.
        ->and(array_keys($context))->toBe([
            'currency', 'exchange_rate_id', 'effective_from', 'evaluated_at', 'threshold_minutes',
        ]);
});

it('logs no credentials or arbitrary data with the stale warning', function (): void {
    $logged = '';
    Log::listen(function ($message) use (&$logged): void {
        $logged .= $message->message.' '.json_encode($message->context);
    });

    expect(staleAt(90))->toBeTrue();

    foreach (['password', 'token', 'api_key', 'authorization', 'credential', 'secret'] as $forbidden) {
        expect(strtolower($logged))->not->toContain($forbidden);
    }
});

it('emits no stale warning when the rate is fresh', function (): void {
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message;
    });

    expect(staleAt(5))->toBeFalse();

    expect($logged)->not->toContain('pricing.fx_rate_stale');
});

it('still reports a missing rate as missing rather than stale', function (): void {
    try {
        $this->pricing->quoteNewSale($this->catalog->price, $this->at);
        $this->fail('A sale was quoted with no exchange rate at all.');
    } catch (SaleNotAvailable $refusal) {
        expect($refusal->reason)->toBe(SaleRefusalReason::MissingFxRate);
    }
});

it('still refuses the sale as stale when logging throws', function (): void {
    // The warning is a signal, never the mechanism. A dead log sink must not
    // permit the sale, and must not turn a clear domain refusal into an
    // infrastructure error either.
    Log::listen(function (): void {
        throw new RuntimeException('the log sink is down');
    });

    $this->rates->recordManualRate('EUR', '90000', $this->owner, $this->at->copy()->subMinutes(90));

    try {
        $this->pricing->quoteNewSale($this->catalog->price, $this->at);
        $this->fail('A sale was quoted on a stale rate because logging failed.');
    } catch (SaleNotAvailable $refusal) {
        expect($refusal->reason)->toBe(SaleRefusalReason::StaleFxRate);
    }

    expect($this->catalog->price->fresh()->selling_price_toman)->toBe(1_500_000);
});

it('uses an immutable instant for the evaluation moment', function (): void {
    $this->rates->recordManualRate('EUR', '90000', $this->owner, $this->at->copy()->subMinute());

    $quote = $this->pricing->quoteNewSale($this->catalog->price, $this->at);

    expect($quote->evaluatedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($quote->evaluatedAt->toDateTimeString())->toBe(EVALUATED_AT);
});
