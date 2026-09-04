<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Audit\AuditEvent;
use App\Audit\AuditRecorder;
use App\Enums\ExchangeRateSource;
use App\Enums\Permission;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Pricing\Exceptions\InvalidExchangeRate;
use App\Pricing\Exceptions\UnauthorizedRateChange;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Records exchange rates, and answers which one applies.
 *
 * The table is a history and this service keeps it one: recording a rate is
 * always an insert. Nothing here updates or deletes a row, because an order
 * priced last week was priced at last week's rate and changing that row would
 * change what the customer was told they were paying.
 *
 * Rates are decimal strings from the database to the caller. No method here
 * accepts or returns a float.
 */
final readonly class ExchangeRateService
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Record a rate entered by an administrator.
     *
     * The rate arrives as a string or a BigDecimal, never a float: by the time
     * a float reached this method the precision would already be gone.
     */
    public function recordManualRate(
        string $currency,
        string|BigDecimal $rateToToman,
        User $actor,
        ?DateTimeInterface $effectiveFrom = null,
    ): ExchangeRate {
        if (! $actor->isActive() || ! $actor->checkPermissionTo(Permission::SettingsManage->value)) {
            throw UnauthorizedRateChange::forActor();
        }

        $currency = self::normalizeCurrency($currency);
        $rate = $this->parseRate($rateToToman);

        $rateRow = ExchangeRate::query()->create([
            'currency' => $currency,
            'rate_to_toman' => (string) $rate,
            'source' => ExchangeRateSource::Manual,
            'effective_from' => $effectiveFrom === null ? Carbon::now() : Carbon::instance(Carbon::parse($effectiveFrom)),
            'created_by_admin_id' => $actor->getKey(),
        ]);

        $this->audit->record(
            AuditEvent::ExchangeRateRecorded,
            actor: $actor,
            subject: $rateRow,
            after: ['rate_to_toman' => $rateRow->rate_to_toman],
            metadata: [
                'exchange_rate_id' => $rateRow->getKey(),
                'currency' => $rateRow->currency,
                'rate_to_toman' => $rateRow->rate_to_toman,
                'effective_from' => $rateRow->effective_from->toIso8601String(),
                'source' => ExchangeRateSource::Manual->value,
            ],
        );

        return $rateRow;
    }

    /**
     * The rate that applies to a currency at a moment, or null if none does.
     *
     * Applicable means already in effect: a rate dated tomorrow is stored today
     * and ignored until tomorrow, so a scheduled change cannot start pricing
     * sales early. Among rates already in effect the newest wins, and where two
     * share an `effective_from` the later-inserted row does — an operator
     * correcting a rate they just entered means the correction.
     */
    public function currentRate(string $currency, ?DateTimeInterface $at = null): ?ExchangeRate
    {
        $at = $at === null ? Carbon::now() : Carbon::instance(Carbon::parse($at));

        $rate = ExchangeRate::query()
            ->where('currency', self::normalizeCurrency($currency))
            ->where('effective_from', '<=', $at)
            ->latest('effective_from')
            ->latest('id')
            ->first();

        return $rate instanceof ExchangeRate ? $rate : null;
    }

    /**
     * How old the applicable rate is, in whole minutes, or null if none applies.
     *
     * Measured from `effective_from` rather than from when the row was written:
     * what matters to a customer is how long ago the number stopped being
     * checked, not when somebody typed it.
     */
    public function ageInMinutes(ExchangeRate $rate, ?DateTimeInterface $at = null): int
    {
        $at = $at === null ? Carbon::now() : Carbon::instance(Carbon::parse($at));

        // Negative would mean a rate not yet in effect, which currentRate()
        // never returns; clamped so a caller cannot read a future rate as
        // impossibly fresh.
        return max(0, (int) $rate->effective_from->diffInMinutes($at, absolute: false));
    }

    /**
     * Currency codes are compared, so they are stored one way.
     */
    public static function normalizeCurrency(string $currency): string
    {
        $normalized = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            throw InvalidExchangeRate::currency($currency);
        }

        return $normalized;
    }

    private function parseRate(string|BigDecimal $rate): BigDecimal
    {
        try {
            $decimal = $rate instanceof BigDecimal ? $rate : BigDecimal::of($rate);
        } catch (MathException) {
            throw InvalidExchangeRate::notANumber((string) $rate);
        }

        if ($decimal->isLessThanOrEqualTo(0)) {
            throw InvalidExchangeRate::notPositive((string) $decimal);
        }

        return $decimal;
    }
}
