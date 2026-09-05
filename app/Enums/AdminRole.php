<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The privileged roles.
 *
 * Named AdminRole rather than Role to stay distinct from the persisted
 * Spatie\Permission\Models\Role that these are provisioned into.
 *
 * There is no `is_admin` flag anywhere in this system: holding one of these
 * roles is the single definition of being privileged.
 */
enum AdminRole: string
{
    case Owner = 'owner';
    case Finance = 'finance';
    case Support = 'support';

    /**
     * The permissions this role is granted.
     *
     * Written out per role rather than derived, so that a change in what
     * support can do is a visible edit here and shows up in review.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Full operational access.
            self::Owner => Permission::cases(),

            // Money, and the customer context needed to act on it. No power
            // over servers or provisioning.
            self::Finance => [
                Permission::AdminAccess,
                Permission::CustomersView,
                Permission::PaymentsView,
                Permission::PaymentsManage,
                Permission::RefundsManage,
                Permission::WalletAdjust,
                Permission::InvoicesView,
                Permission::InvoicesManage,
                Permission::FinancialReportsView,
                Permission::AuditView,
            ],

            // Day-to-day customer and server operations. Deliberately holds no
            // financial permission: support can see that an order exists and
            // act on the server, but cannot move money.
            self::Support => [
                Permission::AdminAccess,
                Permission::CustomersView,
                Permission::CustomersManage,
                Permission::OrdersView,
                Permission::OrdersManage,
                Permission::ServersView,
                Permission::ServersManage,
                Permission::AuditView,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            $this->permissions(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
