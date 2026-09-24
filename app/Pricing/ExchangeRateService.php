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
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    public function __construct(
        private AuditRecorder $audit,
        private FxAuthorityLock $authority,
    ) {}

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
        $effectiveAt = $effectiveFrom === null ? Carbon::now() : Carbon::instance(Carbon::parse($effectiveFrom));

        // One transaction, holding this currency's authority exclusively.
        //
        // A rate is appended rather than updated, so the new row becomes the
        // answer `currentRate()` gives the moment it commits — which means an
        // order priced against the previous rate and still uncommitted would be
        // committing under authority that had already moved. No row lock can
        // hold back an INSERT, so the coordination is explicit: this waits for
        // any sale currently pricing in this currency, and those sales are
        // short because none of them does network work.
        //
        // The audit entry is written inside the same transaction, so a recorded
        // rate and the record of who recorded it cannot come apart.
        return DB::transaction(function () use ($currency, $rate, $effectiveAt, $actor): ExchangeRate {
            $this->authority->exclusive($currency);

            $rateRow = ExchangeRate::query()->create([
                'currency' => $currency,
                'rate_to_toman' => (string) $rate,
                'source' => ExchangeRateSource::Manual,
                'effective_from' => $effectiveAt,
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
        });
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
        $at = self::instant($at);

        $rate = ExchangeRate::query()
            ->where('currency', self::normalizeCurrency($currency))
            ->where('effective_from', '<=', $at)
            ->latest('effective_from')
            ->latest('id')
            ->first();

        return $rate instanceof ExchangeRate ? $rate : null;
    }

    /**
     * Whether a rate is older than the given limit. The authoritative answer.
     *
     * Compares two instants directly rather than measuring an elapsed duration
     * and rounding it. Whole minutes would give a rate 60 minutes and one
     * second old an age of 60, so at a 60-minute limit it would price sales
     * for another 59 seconds after it expired — a grace period nobody granted.
     *
     * The boundary, stated once: a rate is fresh while its `effective_from` is
     * at or after `evaluated_at − limit`, and stale as soon as it is earlier.
     * So exactly 60:00 old is fresh at a 60-minute limit, and 60:00:01 is not.
     */
    public function isStale(ExchangeRate $rate, int $maxAgeMinutes, ?DateTimeInterface $at = null): bool
    {
        $at = self::instant($at);
        $freshFrom = $at->subMinutes($maxAgeMinutes);

        return CarbonImmutable::instance($rate->effective_from)->lessThan($freshFrom);
    }

    /**
     * How old the applicable rate is, in whole minutes.
     *
     * For messages and diagnostics, where a human wants a round number.
     * Deliberately not the freshness decision: it truncates, and truncation is
     * exactly what made the old boundary wrong.
     *
     * Measured from `effective_from` rather than from when the row was written:
     * what matters to a customer is how long ago the number stopped being
     * checked, not when somebody typed it.
     */
    public function ageInMinutes(ExchangeRate $rate, ?DateTimeInterface $at = null): int
    {
        $at = self::instant($at);

        // Negative would mean a rate not yet in effect, which currentRate()
        // never returns; clamped so a caller cannot read a future rate as
        // impossibly fresh.
        return max(0, (int) CarbonImmutable::instance($rate->effective_from)->diffInMinutes($at, absolute: false));
    }

    /**
     * One moment, as an immutable value.
     *
     * Immutable so that a caller holding the result cannot shift the instant a
     * decision was made by acting on the object afterwards.
     */
    public static function instant(?DateTimeInterface $at = null): CarbonImmutable
    {
        return $at === null ? CarbonImmutable::now() : CarbonImmutable::instance(Carbon::parse($at));
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
