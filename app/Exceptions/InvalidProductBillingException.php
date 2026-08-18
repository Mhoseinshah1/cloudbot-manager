<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

/**
 * Thrown when a product's billing configuration is invalid (missing or
 * non-positive prices, missing monthly cap, etc.). Raised by the domain
 * validator so service/API calls fail even when Filament forms are bypassed.
 */
class InvalidProductBillingException extends RuntimeException
{
    public static function forProduct(Product $product, string $reason): self
    {
        return new self("Product [{$product->slug}] has an invalid billing configuration: {$reason}.");
    }
}
