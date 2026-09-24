<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ServerStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Server;
use App\Models\Subscription;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * What the platform looks like right now.
 *
 * Every figure is an aggregate query — counts and sums computed by PostgreSQL,
 * never rows loaded and tallied in PHP — so the dashboard stays usable when the
 * estate is large. Nothing here calls a provider: a dashboard that made network
 * calls would be slowest exactly when somebody needs it most.
 *
 * Gross sales counts issued invoices, which is this system's record of a
 * customer actually being charged for something. Wallet top-ups are excluded on
 * purpose: money moved into a wallet is a liability, not a sale, and counting
 * it would overstate revenue by every rial a customer has not yet spent.
 */
class PlatformOverview extends StatsOverviewWidget
{
    use AuthorizesWithPermission;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function viewPermission(): Permission
    {
        return Permission::AdminAccess;
    }

    public static function canView(): bool
    {
        return self::operatorMay(Permission::AdminAccess);
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $today = now()->startOfDay();

        $activeServers = Server::query()->where('status', ServerStatus::Active->value)->count();
        $activeSubscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->count();

        $ordersToday = Order::query()->where('created_at', '>=', $today)->count();
        $provisionedToday = Order::query()
            ->where('status', OrderStatus::Provisioned->value)
            ->where('updated_at', '>=', $today)
            ->count();
        $failedToday = Order::query()
            ->whereIn('status', [OrderStatus::Failed->value, OrderStatus::NeedsAttention->value])
            ->where('updated_at', '>=', $today)
            ->count();

        // Issued invoices only: the document that says a customer was charged.
        $grossSales = (int) Invoice::query()
            ->where('status', InvoiceStatus::Issued->value)
            ->sum('amount_toman');

        // What the platform owes customers who have not spent it yet.
        $walletLiabilities = (int) User::query()
            ->whereIn('status', [UserStatus::Active->value, UserStatus::Suspended->value])
            ->sum('wallet_balance_toman');

        $byProvider = DB::table('servers')
            ->join('providers', 'providers.id', '=', 'servers.provider_id')
            ->where('servers.status', ServerStatus::Active->value)
            ->groupBy('providers.code')
            ->selectRaw('providers.code as code, count(*) as total')
            ->pluck('total', 'code');

        return [
            Stat::make('Active servers', number_format($activeServers))
                ->description($byProvider->isEmpty()
                    ? 'None'
                    : $byProvider->map(fn (int $n, string $code): string => "{$code}: {$n}")->implode('  ·  '))
                ->color('success'),

            Stat::make('Active subscriptions', number_format($activeSubscriptions)),

            Stat::make('Orders today', number_format($ordersToday))
                ->description($provisionedToday.' provisioned  ·  '.$failedToday.' failed or parked')
                ->color($failedToday > 0 ? 'warning' : 'success'),

            Stat::make('Gross sales', number_format($grossSales).' T')
                ->description('Issued invoices. Wallet top-ups are not sales.'),

            Stat::make('Wallet liabilities', number_format($walletLiabilities).' T')
                ->description('Customer balances this platform owes')
                ->color('warning'),
        ];
    }
}
