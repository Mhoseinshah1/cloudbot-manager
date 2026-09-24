<?php

declare(strict_types=1);

namespace App\Cloud\Hetzner;

use App\Cloud\Enums\ProviderErrorCategory;
use App\Cloud\Exceptions\ProviderException;

/**
 * Turns a Hetzner failure into one of the categories business code understands.
 *
 * Decisions are made from machine-readable codes and HTTP status, never from
 * the human-readable message: Hetzner is free to reword its errors, and a
 * system whose refund behaviour depends on English prose will change behaviour
 * the day somebody fixes a typo. The message is carried only so a person
 * reading a log has something to go on, and it is scrubbed on the way through
 * `ProviderException::make`.
 *
 * The distinction that matters most is not "did it fail" but "does anybody know
 * what happened". A read that times out is a `Timeout`; a *write* that times out
 * is `UncertainResult`, because the request may well have arrived and the
 * machine may well exist. Getting that wrong is how a customer is refunded for
 * a server they own, or charged twice for one they only asked for once.
 */
final readonly class HetznerErrorMapper
{
    /**
     * Hetzner error codes that mean the requested capacity is not there.
     *
     * Kept as a list rather than a prefix match: guessing from a substring is
     * how an unrelated new code silently starts refunding customers.
     */
    private const OUT_OF_STOCK = [
        'resource_unavailable',
        'no_space_left_in_location',
        'placement_error',
        'server_type_not_available_in_location',
        'resource_limit_exceeded',
    ];

    private const INVALID_REQUEST = [
        'invalid_input',
        'json_error',
        'not_found',
        'unsupported_error',
        'invalid_server_type',
        'server_already_attached',
        'firewall_already_applied',
    ];

    /** The account cannot pay for, or is not permitted, this operation. */
    private const BILLING = [
        'no_subnet_available',
        'resource_limit_exceeded_error',
    ];

    /**
     * Map an HTTP failure into a normalized category.
     *
     * @param  array<string, mixed>  $body  The decoded response, if any.
     */
    public function fromResponse(int $status, array $body, string $operation): ProviderException
    {
        $code = self::errorCode($body);

        $category = $this->categoryFor($status, $code);

        return ProviderException::make(
            $category,
            HetznerProvider::CODE,
            self::message($body, $status),
            // Identifiers only. Never the request body, never a header, never
            // the response: a Hetzner error quotes back what was sent.
            array_filter([
                'http_status' => $status,
                'hetzner_code' => $code,
                'operation' => $operation,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    /**
     * What a normalized action error means, for a polled action that failed.
     *
     * Returns null when the code is not one this adapter recognises. That is a
     * deliberate answer rather than a gap: `ProviderActionData` treats a null
     * category as "nobody has established what this means", and the server
     * action layer then parks it for a person instead of inventing permission
     * to repeat a delete.
     */
    public function actionCategory(?string $code): ?ProviderErrorCategory
    {
        if ($code === null || $code === '') {
            return null;
        }

        if (in_array($code, self::OUT_OF_STOCK, true)) {
            return ProviderErrorCategory::OutOfStock;
        }

        if (in_array($code, self::INVALID_REQUEST, true)) {
            return ProviderErrorCategory::InvalidRequest;
        }

        return match ($code) {
            'rate_limit_exceeded' => ProviderErrorCategory::RateLimited,
            'unauthorized' => ProviderErrorCategory::Authentication,
            'forbidden' => ProviderErrorCategory::Authorization,
            // The machine is busy with another operation. Nothing happened, and
            // asking again later is exactly the right response.
            'locked', 'conflict', 'service_error', 'maintenance' => ProviderErrorCategory::TransientProviderError,
            'timeout' => ProviderErrorCategory::Timeout,
            'protected' => ProviderErrorCategory::InvalidRequest,
            default => null,
        };
    }

    /** A connection that never opened: the request cannot have arrived. */
    public function connectionFailed(string $operation): ProviderException
    {
        return ProviderException::make(
            ProviderErrorCategory::Unavailable,
            HetznerProvider::CODE,
            'The Hetzner API could not be reached.',
            ['operation' => $operation],
        );
    }

    /**
     * A request that went out and never came back.
     *
     * `$mutating` is the whole point of this method. For a read the answer is
     * simply unknown to us and harmless to ask again. For a write — a create,
     * a delete, a reboot — the request may have been received and acted on, and
     * the only honest category is the one that forces reconciliation.
     */
    public function timedOut(string $operation, bool $mutating): ProviderException
    {
        return ProviderException::make(
            $mutating ? ProviderErrorCategory::UncertainResult : ProviderErrorCategory::Timeout,
            HetznerProvider::CODE,
            $mutating
                ? 'The Hetzner API did not answer, and the operation may have taken effect.'
                : 'The Hetzner API did not answer in time.',
            ['operation' => $operation],
        );
    }

    /** A 200 whose body is not the shape the contract needs. */
    public function malformed(string $operation, string $detail): ProviderException
    {
        return ProviderException::make(
            ProviderErrorCategory::TransientProviderError,
            HetznerProvider::CODE,
            'The Hetzner API returned a response this adapter cannot read: '.$detail,
            ['operation' => $operation],
        );
    }

    private function categoryFor(int $status, ?string $code): ProviderErrorCategory
    {
        if ($code !== null) {
            if (in_array($code, self::OUT_OF_STOCK, true)) {
                return ProviderErrorCategory::OutOfStock;
            }

            if (in_array($code, self::BILLING, true)) {
                return ProviderErrorCategory::InsufficientProviderBalance;
            }

            if ($code === 'rate_limit_exceeded') {
                return ProviderErrorCategory::RateLimited;
            }

            if ($code === 'locked' || $code === 'conflict' || $code === 'maintenance') {
                return ProviderErrorCategory::TransientProviderError;
            }
        }

        return match (true) {
            $status === 401 => ProviderErrorCategory::Authentication,
            $status === 403 => ProviderErrorCategory::Authorization,
            // Never absence on its own. Only a caller that asked for one named
            // resource may read a 404 as "this does not exist", and that
            // decision is made there rather than here.
            $status === 404 => ProviderErrorCategory::InvalidRequest,
            $status === 409 => ProviderErrorCategory::TransientProviderError,
            $status === 422 || $status === 400 => ProviderErrorCategory::InvalidRequest,
            $status === 429 => ProviderErrorCategory::RateLimited,
            $status === 503 => ProviderErrorCategory::Unavailable,
            $status >= 500 => ProviderErrorCategory::TransientProviderError,
            default => ProviderErrorCategory::TransientProviderError,
        };
    }

    /**
     * Hetzner's machine-readable code, when the body carries one.
     *
     * @param  array<string, mixed>  $body  The decoded response, if any.
     */
    public static function errorCode(array $body): ?string
    {
        $error = $body['error'] ?? null;

        if (! is_array($error)) {
            return null;
        }

        $code = $error['code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** @param array<string, mixed> $body The decoded response, if any. */
    private static function message(array $body, int $status): string
    {
        $error = $body['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? null) : null;

        return is_string($message) && $message !== ''
            ? 'Hetzner refused the request: '.$message
            : 'Hetzner refused the request with HTTP '.$status.'.';
    }
}
