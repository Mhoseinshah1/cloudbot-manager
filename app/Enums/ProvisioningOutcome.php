<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one provisioning attempt ended.
 *
 * Forensic history, written once per attempt and then left alone. An operator
 * reading these months later is asking what the provider actually did at the
 * time, so a later reconciliation learning more never rewrites an earlier
 * attempt: it records its own. An attempt that says `uncertain` is a true
 * statement about that call even after the server turns up.
 *
 * Stable and normalized. Nothing here is derived from a provider's prose.
 */
enum ProvisioningOutcome: string
{
    /** Started, not yet concluded. Written before the provider is called. */
    case InFlight = 'in_flight';

    /** The provider created a server and we have its identity. */
    case Succeeded = 'succeeded';

    /** The token already had a server; this attempt found it rather than making one. */
    case RecoveredExisting = 'recovered_existing';

    /** Capacity was gone before anything was sent. No server exists. */
    case AvailabilityLost = 'availability_lost';

    /** The provider answered, and its answer was no. No server exists. */
    case RejectedNoServer = 'rejected_no_server';

    /** A transient fault. Says nothing about whether a server exists. */
    case TransientFailure = 'transient_failure';

    /** The call may or may not have created a server. Reconcile; never refund. */
    case Uncertain = 'uncertain';

    /** The provider created a server and storing it locally failed. */
    case RemoteCreatedLocalFailed = 'remote_created_local_failed';

    /** A server came back that is not the one this order asked for. */
    case IdentityMismatch = 'identity_mismatch';

    /** More than one remote server claims this token. Never resolved automatically. */
    case AmbiguousRemoteMatch = 'ambiguous_remote_match';

    /**
     * Whether the remote outcome of this attempt is unknown.
     *
     * These are the outcomes that must be reconciled against the provider
     * before anybody decides a server does not exist.
     */
    public function isOutcomeUnknown(): bool
    {
        return in_array($this, [
            self::InFlight,
            self::TransientFailure,
            self::Uncertain,
            self::RemoteCreatedLocalFailed,
            self::AmbiguousRemoteMatch,
        ], strict: true);
    }

    /** Whether this attempt is known to have left no remote server behind. */
    public function isConfirmedNoServer(): bool
    {
        return in_array($this, [self::AvailabilityLost, self::RejectedNoServer], strict: true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
