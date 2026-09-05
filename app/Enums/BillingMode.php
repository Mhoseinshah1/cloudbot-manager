<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a product is charged for.
 *
 * Release 1.0 sells monthly and nothing else, so this enum has exactly one
 * case and the database refuses any other value. The field is nonetheless
 * separate from the billing cycle because Release 1.1 introduces hourly and
 * hourly-capped strategies, where mode and cycle stop agreeing — adding the
 * column later would mean migrating live products rather than relaxing a
 * constraint.
 *
 * A new case here is a Release 1.1 change and needs a migration to widen the
 * CHECK constraint alongside it. Adding one without that migration would make
 * the application believe in a value the database will reject.
 */
enum BillingMode: string
{
    case Monthly = 'monthly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
