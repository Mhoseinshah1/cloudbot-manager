<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a new sale was refused.
 *
 * A stable machine-readable vocabulary, so that a Telegram flow or an admin
 * screen can decide what to say without reading an exception message. Message
 * text is for people and will be rewritten; these values are an interface and
 * must not be.
 *
 * Every one of these means the same thing: no sale. They differ only in what
 * an operator should do about it.
 */
enum SaleRefusalReason: string
{
    /** The operator has turned new sales off. */
    case SalesDisabled = 'sales_disabled';

    /**
     * A setting needed to judge whether selling is safe is missing or unreadable.
     *
     * Distinct from `sales_disabled`: nobody decided this, which is exactly why
     * it cannot be treated as permission to sell.
     */
    case SalesConfigurationMissing = 'sales_configuration_missing';

    /** The product, its provider, or its provider plan is not sellable. */
    case UnavailableProduct = 'unavailable_product';

    /** The location, or its price row, is not sellable. */
    case UnavailableLocation = 'unavailable_location';

    /** The catalog rows do not belong together. Never a customer-facing situation. */
    case InvalidCatalogRelationship = 'invalid_catalog_relationship';

    /** No provider cost has been recorded, so margin is unknown. */
    case MissingProviderCost = 'missing_provider_cost';

    /** No exchange rate applies to the provider's currency yet. */
    case MissingFxRate = 'missing_fx_rate';

    /** The applicable rate is older than the configured freshness limit. */
    case StaleFxRate = 'stale_fx_rate';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
