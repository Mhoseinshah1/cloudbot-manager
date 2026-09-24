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

    /*
    |--------------------------------------------------------------------------
    | Operations
    |--------------------------------------------------------------------------
    |
    | Added for the staff panel. Read and act are separated throughout, because
    | the panel's whole safety model is that seeing a thing and changing it are
    | different privileges: support reads a provisioning attempt to answer a
    | customer, and that is nothing like being allowed to retry it.
    |
    */

    case SubscriptionsView = 'subscriptions.view';
    case SubscriptionsManage = 'subscriptions.manage';

    case ProvidersView = 'providers.view';
    case ProvidersManage = 'providers.manage';

    /** Replacing an API token. Separate from ProvidersManage on purpose. */
    case ProviderCredentialsManage = 'provider_credentials.manage';

    /** The operator's own switches on synced catalog rows. */
    case ProviderCatalogManage = 'provider_catalog.manage';

    case ProvisioningView = 'provisioning.view';
    case ProvisioningManage = 'provisioning.manage';

    case ServerActionsView = 'server_actions.view';

    case WalletView = 'wallet.view';

    case NotificationsView = 'notifications.view';

    case JobsView = 'jobs.view';
    case JobsManage = 'jobs.manage';

    case AlertsView = 'alerts.view';

    /** Reading settings without being able to change any of them. */
    case SettingsView = 'settings.view';

    case InventoryView = 'inventory.view';
    case InventoryManage = 'inventory.manage';

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
