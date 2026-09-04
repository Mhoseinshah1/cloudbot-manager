<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an invoice is for.
 *
 * One case, because one thing can currently be invoiced: money added to a
 * wallet. Order and renewal invoices arrive with orders and subscriptions.
 */
enum InvoiceType: string
{
    case WalletTopUp = 'wallet_top_up';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
