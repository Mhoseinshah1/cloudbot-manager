<?php

declare(strict_types=1);

namespace App\Cloud\Data;

use App\Support\Secrets\SecretScrubber;
use InvalidArgumentException;

/**
 * Everything needed to create one server, and the token that makes it safe to
 * ask twice.
 *
 * The provisioning token is the whole reason this is a request object rather
 * than a list of arguments. It is committed locally before the provider is ever
 * called, travels with every attempt, and is what lets a retry find the server
 * a previous attempt may already have created instead of creating a second one.
 */
final readonly class CreateServerRequest
{
    /**
     * @param  array<string, scalar|null>  $labels  Safe provider labels or tags.
     */
    public function __construct(
        public string $provisioningToken,
        public string $providerPlanId,
        public string $providerLocationId,
        public string $providerImageId,
        public string $name,
        public array $labels = [],
    ) {
        if (trim($provisioningToken) === '') {
            // Without a token a retry cannot be told apart from a new order,
            // and the customer ends up paying for two servers.
            throw new InvalidArgumentException('A provisioning token is required.');
        }

        foreach ($labels as $key => $value) {
            if (! is_string($key) || SecretScrubber::isSecretKey($key)) {
                // Labels are sent to a third party and come back in responses
                // and logs; a credential must never travel as one.
                throw new InvalidArgumentException('Labels must not carry credentials.');
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Labels must be scalar values.');
            }
        }
    }
}
