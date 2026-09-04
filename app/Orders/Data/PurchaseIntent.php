<?php

declare(strict_types=1);

namespace App\Orders\Data;

use App\Models\ProductLocationPrice;
use App\Models\User;

/**
 * What a customer says they want to buy.
 *
 * Everything a purchase decision depends on, in one value, so that a retry
 * carrying the same idempotency key can be compared against the order that
 * already exists field by field. Anything absent from here cannot be part of
 * that comparison, which is why the accepted terms version and the chosen image
 * are in it: a retry that quietly changed either would be a different purchase
 * wearing the same key.
 *
 * There is no price. A customer states what they want; what it costs is decided
 * by PricingService at the moment the order is created, and accepting a claimed
 * price from a caller would be accepting a claimed price from a customer.
 */
final readonly class PurchaseIntent
{
    public function __construct(
        public User $user,
        public ProductLocationPrice $locationPrice,
        /** The terms version the customer says they accepted. */
        public string $acceptedAupVersion,
        /** Whether they actually accepted. False is not a purchase. */
        public bool $aupAccepted,
        /** Makes a retry recognisable as the same purchase. */
        public string $idempotencyKey,
        /** Chosen operating system image, or null to take the location's default. */
        public ?int $providerImageId = null,
    ) {}
}
