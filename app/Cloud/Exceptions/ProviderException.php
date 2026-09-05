<?php

declare(strict_types=1);

namespace App\Cloud\Exceptions;

use App\Cloud\Enums\ProviderErrorCategory;
use App\Support\Secrets\SecretScrubber;
use RuntimeException;

/**
 * A provider failure, normalized.
 *
 * Carries a category rather than a status code or a provider message, so the
 * code that decides whether to retry, refund or reconcile never has to read
 * prose. The message and context are scrubbed on the way in, because an
 * exception is one of the most likely things to end up in a log.
 */
final class ProviderException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly ProviderErrorCategory $category,
        public readonly string $providerCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(
        ProviderErrorCategory $category,
        string $providerCode,
        string $message,
        array $context = [],
    ): self {
        return new self(
            $category,
            $providerCode,
            // A provider's own text can quote back what we sent it, including
            // an authorization header.
            SecretScrubber::scrubText($message),
            SecretScrubber::scrub($context),
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function outOfStock(string $providerCode, string $message, array $context = []): self
    {
        return self::make(ProviderErrorCategory::OutOfStock, $providerCode, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function invalidRequest(string $providerCode, string $message, array $context = []): self
    {
        return self::make(ProviderErrorCategory::InvalidRequest, $providerCode, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function unavailable(string $providerCode, string $message, array $context = []): self
    {
        return self::make(ProviderErrorCategory::Unavailable, $providerCode, $message, $context);
    }

    /**
     * The remote outcome is unknown. Reconcile before concluding anything.
     */
    /**
     * @param  array<string, mixed>  $context
     */
    public static function uncertain(string $providerCode, string $message, array $context = []): self
    {
        return self::make(ProviderErrorCategory::UncertainResult, $providerCode, $message, $context);
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->category->isOutcomeUnknown();
    }
}
