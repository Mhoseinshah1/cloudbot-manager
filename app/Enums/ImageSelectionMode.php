<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a customer arrived at the operating system their server will run.
 *
 * Two different purchase intentions, even when they resolve to the same image
 * today. "Give me whatever you recommend here" and "give me Ubuntu 24.04" are
 * distinct requests, and the location's default can change between them — so a
 * retry that switches from one to the other is not the same purchase, and the
 * order has to remember which it was to be able to say so.
 */
enum ImageSelectionMode: string
{
    /** The customer named the image. */
    case Explicit = 'explicit';

    /** The customer took the location's default, whatever it was at the time. */
    case Default = 'default';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
