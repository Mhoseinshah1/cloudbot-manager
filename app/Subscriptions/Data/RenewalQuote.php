<?php

declare(strict_types=1);

namespace App\Subscriptions\Data;

use App\Models\Subscription;
use App\Pricing\Data\PriceQuote;
use Carbon\CarbonImmutable;

/**
 * What one renewal would cost, and what the customer would get for it.
 *
 * Shown before the customer confirms, and re-derived from scratch when they do.
 * This is a display of a decision, never the decision itself: the amount that
 * is actually charged comes from a fresh quote taken inside the settlement
 * transaction, because the price on a screen somebody has been looking at for
 * five minutes is a claim about the past.
 */
final readonly class RenewalQuote
{
    public function __construct(
        public int $subscriptionId,
        public int $serverId,
        public string $serverName,
        /** The period end this renewal replaces — and the renewal's identity. */
        public CarbonImmutable $currentPeriodEnd,
        /** Exactly one fixed period later. Never "now plus thirty days". */
        public CarbonImmutable $newPeriodEnd,
        public int $priceToman,
        public bool $inGrace,
        public ?CarbonImmutable $graceUntil,
        /** The full pricing decision, kept for the invoice snapshot. */
        public PriceQuote $quote,
    ) {}

    /**
     * Where a renewed period ends.
     *
     * Appended to the existing period rather than started from now, so a
     * customer who renews early keeps the days they already paid for, and one
     * who renews inside grace does not get a free extension for being late.
     */
    public static function endAfter(CarbonImmutable $currentEnd): CarbonImmutable
    {
        return $currentEnd->addSeconds(Subscription::PERIOD_SECONDS);
    }

    /** Whether the customer is being asked for a different figure than before. */
    public function differsFrom(?int $shownPrice): bool
    {
        return $shownPrice !== null && $shownPrice !== $this->priceToman;
    }
}
