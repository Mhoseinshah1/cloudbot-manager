<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * Where a notification was sent.
 *
 * Two, and deliberately not one. A message to a customer and an alert to an
 * operator differ in who may see them and in what happens when they fail: a
 * customer who blocked the bot is a fact about that customer, while an
 * unreachable admin channel is an incident. Recording them under one name
 * would lose that.
 */
enum NotificationChannel: string
{
    case TelegramCustomer = 'telegram_customer';

    case TelegramAdmin = 'telegram_admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
