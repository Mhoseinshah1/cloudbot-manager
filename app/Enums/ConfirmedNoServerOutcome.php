<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The outcomes that are known to have left no remote server behind.
 *
 * A separate, smaller enum rather than a flag on OrderFailureCategory, because
 * the refund boundary takes this type and nothing else. There is no value of
 * this enum that means "probably", so a caller holding only a suspicion cannot
 * express it here — it would have to invent a case, which is a visible edit in
 * a review rather than a boolean quietly passed as true.
 *
 * Every case maps onto the category recorded on the order.
 */
enum ConfirmedNoServerOutcome: string
{
    case FailureBeforeProviderCreate = 'failure_before_provider_create';
    case ProviderRejectedNoServer = 'provider_rejected_no_server';
    case AvailabilityLostNoServer = 'availability_lost_no_server';
    case ReconciliationConfirmedNoServer = 'reconciliation_confirmed_no_server';

    /** How this outcome is recorded on the order. */
    public function category(): OrderFailureCategory
    {
        return match ($this) {
            self::FailureBeforeProviderCreate => OrderFailureCategory::FailureBeforeProviderCreate,
            self::ProviderRejectedNoServer => OrderFailureCategory::ProviderRejectedNoServer,
            self::AvailabilityLostNoServer => OrderFailureCategory::AvailabilityLostNoServer,
            self::ReconciliationConfirmedNoServer => OrderFailureCategory::ReconciliationConfirmedNoServer,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
