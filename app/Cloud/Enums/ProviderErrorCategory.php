<?php

declare(strict_types=1);

namespace App\Cloud\Enums;

/**
 * Stable categories every provider failure is normalized into.
 *
 * Business code decides what to do from these, never from an HTTP status or a
 * message string. A provider that renames an error, or a new provider with
 * entirely different wording, must not be able to change how the system
 * behaves — and the difference between "definitely did not create a server"
 * and "might have" is the difference between refunding a customer and
 * double-charging them.
 */
enum ProviderErrorCategory: string
{
    /** Credentials rejected. Never retry; an operator must act. */
    case Authentication = 'authentication';

    /** Authenticated but not permitted to do this. Never retry. */
    case Authorization = 'authorization';

    /** The provider or the requested resource is not currently serving. */
    case Unavailable = 'unavailable';

    /** The plan or location has no capacity right now. */
    case OutOfStock = 'out_of_stock';

    /** Rate limited. Retry after the provider's stated delay. */
    case RateLimited = 'rate_limited';

    /** Our account with the provider cannot fund the operation. */
    case InsufficientProviderBalance = 'insufficient_provider_balance';

    /** We sent something wrong. Retrying unchanged will fail again. */
    case InvalidRequest = 'invalid_request';

    /** No answer in time. For a create, the outcome is unknown. */
    case Timeout = 'timeout';

    /**
     * The operation may or may not have taken effect remotely.
     *
     * The most consequential category in the system: it must never be treated
     * as a failure. A create that ends here has to be reconciled against the
     * provider before anyone decides a server does not exist.
     */
    case UncertainResult = 'uncertain_result';

    /** A transient provider-side fault. Safe to retry reads. */
    case TransientProviderError = 'transient_provider_error';

    /** The remote call worked; storing the result locally did not. */
    case LocalPersistenceError = 'local_persistence_error';

    /**
     * Whether the remote outcome is unknown after this failure.
     *
     * A create that ends in one of these must be reconciled, never assumed
     * to have failed.
     */
    public function isOutcomeUnknown(): bool
    {
        return in_array($this, [self::Timeout, self::UncertainResult, self::LocalPersistenceError], true);
    }

    /**
     * Whether repeating the identical request could plausibly succeed.
     *
     * Creates are excluded from this judgement on purpose: a create is only
     * ever retried through the provisioning token, never because an error
     * looked transient.
     */
    public function isRetryable(): bool
    {
        return in_array($this, [
            self::RateLimited,
            self::TransientProviderError,
            self::Unavailable,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
