<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every privileged capability in the system.
 *
 * Naming is `<area>.<action>` so a permission reads the same in a policy, a
 * test and a seeded row. The list is deliberately declared up front, before the
 * features that consume it exist, so that authorization is designed once rather
 * than accreting a new ad-hoc check per feature.
 *
 * Cases whose feature has not been built yet still belong here: they are how
 * this phase can prove that, for example, support never gains the ability to
 * adjust a wallet balance.
 */
enum Permission: string
{
    /** Enter the admin panel at all. Every privileged role holds this. */
    case AdminAccess = 'admin.access';

    // Customer operations.
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';

    // Sales and infrastructure operations.
    case OrdersView = 'orders.view';
    case OrdersManage = 'orders.manage';
    case ServersView = 'servers.view';
    case ServersManage = 'servers.manage';

    // Financial operations. These are the ones support must never hold.
    case PaymentsView = 'payments.view';
    case PaymentsManage = 'payments.manage';
    case RefundsManage = 'refunds.manage';
    case WalletAdjust = 'wallet.adjust';
    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';
    case FinancialReportsView = 'reports.financial.view';

    // Administration of the system itself.
    case AuditView = 'audit.view';
    case SettingsManage = 'settings.manage';
    case RolesManage = 'roles.manage';

    /**
     * Permissions that move or reveal customer money.
     *
     * Named as a set because the rule that matters is a negative one: no
     * non-financial role may hold any of these.
     *
     * @return list<self>
     */
    public static function financial(): array
    {
        return [
            self::PaymentsView,
            self::PaymentsManage,
            self::RefundsManage,
            self::WalletAdjust,
            self::InvoicesView,
            self::InvoicesManage,
            self::FinancialReportsView,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
