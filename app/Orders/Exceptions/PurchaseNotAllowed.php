<?php

declare(strict_types=1);

namespace App\Orders\Exceptions;

use App\Enums\PurchaseRefusalReason;
use RuntimeException;

/**
 * An abuse control stopped a purchase before anything was created.
 *
 * Carries the numbers behind the decision so a customer can be told what they
 * are up against — a limit is only fair if the person hitting it can see it —
 * and so an operator reading a log can tell a real ceiling from a
 * configuration that never arrived.
 */
final class PurchaseNotAllowed extends RuntimeException
{
    private function __construct(
        public readonly PurchaseRefusalReason $reason,
        public readonly ?int $limit,
        public readonly ?int $observed,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    public static function atServerLimit(int $limit, int $observed): self
    {
        return new self(
            PurchaseRefusalReason::ActiveServerLimitReached,
            $limit,
            $observed,
            "This customer holds {$observed} of {$limit} permitted servers.",
        );
    }

    public static function tooFast(int $limit, int $observed, int $windowMinutes): self
    {
        return new self(
            PurchaseRefusalReason::PurchaseVelocityExceeded,
            $limit,
            $observed,
            "This customer has placed {$observed} orders in the last {$windowMinutes} minutes, and the limit is {$limit}.",
        );
    }

    public static function notConfigured(string $setting): self
    {
        return new self(
            PurchaseRefusalReason::LimitsNotConfigured,
            null,
            null,
            "No usable {$setting} is configured, so new purchases are refused.",
        );
    }
}
