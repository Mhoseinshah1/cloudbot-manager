<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an order did not become a server.
 *
 * Recorded on the order so an operator can tell, months later, what happened.
 * The distinction that matters is whether a remote server exists: refunding a
 * customer whose server was in fact created gives away a machine, and the only
 * honest answer to "we do not know" is to find out rather than to guess.
 *
 * `UncertainResult` is deliberately a member of this enum and deliberately not
 * a member of ConfirmedNoServerOutcome. It can be recorded; it cannot be
 * refunded on.
 */
enum OrderFailureCategory: string
{
    /** Something stopped before any provider request was made. */
    case FailureBeforeProviderCreate = 'failure_before_provider_create';

    /** The provider answered, and its answer was no. */
    case ProviderRejectedNoServer = 'provider_rejected_no_server';

    /** Capacity disappeared between quoting and creating. Nothing was made. */
    case AvailabilityLostNoServer = 'availability_lost_no_server';

    /** Reconciliation looked and found no server. */
    case ReconciliationConfirmedNoServer = 'reconciliation_confirmed_no_server';

    /**
     * The request may or may not have created a server.
     *
     * A timeout after the provider received the call looks exactly like this.
     * Never refundable: reconciliation resolves it into one of the confirmed
     * outcomes above, or into a real server.
     */
    case UncertainResult = 'uncertain_result';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
