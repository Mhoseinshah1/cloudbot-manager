<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ServerStatus;
use App\Enums\SettingKey;
use App\Filament\Support\AuthorizesWithPermission;
use App\Models\ExchangeRate;
use App\Models\FailedJob;
use App\Models\Order;
use App\Models\ProductLocationPrice;
use App\Models\Provider;
use App\Models\Server;
use App\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * The conditions somebody needs to know about, computed from local state.
 *
 * Deliberately not a parallel alerting system: it reads facts this application
 * already persists — parked orders, missing servers, stale rates, failed jobs,
 * a wallet that does not match its ledger — and puts them on one screen. The
 * durable notifications that go to administrators are still the outbox's, and
 * nothing here sends anything.
 *
 * No provider is contacted. Every check is a local query, because a dashboard
 * that made network calls would be slowest exactly when an incident is in
 * progress, and would add load to a provider that may already be the problem.
 *
 * Backup alerting is not here. Backups arrive in a later phase, and an alert
 * that cannot actually detect a failed backup would be worse than none: it
 * would read as green.
 */
class OperationalAlerts extends Widget
{
    use AuthorizesWithPermission;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.operational-alerts';

    public static function viewPermission(): Permission
    {
        return Permission::AlertsView;
    }

    public static function canView(): bool
    {
        return self::operatorMay(Permission::AlertsView);
    }

    /**
     * @return list<array{level: string, title: string, detail: string}>
     */
    public function alerts(): array
    {
        $alerts = [];

        $parked = Order::query()->where('status', OrderStatus::NeedsAttention->value)->count();

        if ($parked > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => $parked.' order(s) parked for a person',
                'detail' => 'Paid work the system will not resolve automatically. Open Stuck orders.',
            ];
        }

        $stale = Order::query()
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Provisioning->value])
            ->where('created_at', '<=', now()->subHour())
            ->count();

        if ($stale > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => $stale.' order(s) paid over an hour ago and still not delivered',
                'detail' => 'A customer is waiting for a server they bought.',
            ];
        }

        $missing = Server::query()
            ->whereIn('status', [ServerStatus::Missing->value, ServerStatus::NeedsAttention->value])
            ->count();

        if ($missing > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => $missing.' server(s) missing or unhealthy',
                'detail' => 'Customers may be paying for machines the provider cannot confirm.',
            ];
        }

        $disabled = Provider::query()->where('enabled', false)->count();

        if ($disabled > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => $disabled.' provider(s) disabled',
                'detail' => 'No new provisioning through them. Existing servers are unaffected.',
            ];
        }

        $noCredential = Provider::query()
            ->where('enabled', true)
            ->whereDoesntHave('credentials', fn ($query) => $query->where('is_active', true))
            ->count();

        if ($noCredential > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => $noCredential.' enabled provider(s) have no active credential',
                'detail' => 'Every call to them will fail authentication.',
            ];
        }

        foreach ($this->staleCurrencies() as $currency => $detail) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Exchange rate for '.$currency.' is '.$detail,
                'detail' => 'Sales and renewals priced in this currency are refused while the rate is unusable.',
            ];
        }

        $missingCost = ProductLocationPrice::query()
            ->where('active', true)
            ->whereNull('provider_cost_snapshot')
            ->count();

        if ($missingCost > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => $missingCost.' active price row(s) have no provider cost',
                'detail' => 'Sales through them are refused. Run providers:sync-pricing.',
            ];
        }

        $failed = FailedJob::query()->count();

        if ($failed > 0) {
            $alerts[] = [
                'level' => $failed > 10 ? 'danger' : 'warning',
                'title' => $failed.' failed job(s)',
                'detail' => 'Work the queue gave up on. Open Failed jobs.',
            ];
        }

        if ($this->walletDrift() > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Wallet balances disagree with the ledger',
                'detail' => 'Run wallet:verify-integrity. This is a financial integrity failure.',
            ];
        }

        foreach ([SettingKey::SalesEnabled, SettingKey::ProvisioningEnabled] as $switch) {
            $value = app(SettingsService::class)->boolean($switch);

            if ($value === false) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => $switch->value.' is off',
                    'detail' => 'Deliberate, if somebody turned it off.',
                ];
            }

            if ($value === null) {
                $alerts[] = [
                    'level' => 'danger',
                    'title' => $switch->value.' is missing or unreadable',
                    'detail' => 'Every reader treats this as off, so the feature is disabled by accident.',
                ];
            }
        }

        return $alerts;
    }

    /**
     * Currencies whose newest rate is too old to price against, or absent.
     *
     * The freshness threshold is the same setting the pricing layer reads, so
     * this screen and the sale refusing agree about what "stale" means.
     *
     * @return array<string, string>
     */
    private function staleCurrencies(): array
    {
        $maxAge = app(SettingsService::class)->integer(SettingKey::FxMaxAgeMinutes);

        if ($maxAge === null || $maxAge <= 0) {
            return [];
        }

        $currencies = ProductLocationPrice::query()
            ->where('active', true)
            ->whereNotNull('provider_currency')
            ->distinct()
            ->pluck('provider_currency');

        $deadline = CarbonImmutable::now()->subMinutes($maxAge);
        $stale = [];

        foreach ($currencies as $currency) {
            $newest = ExchangeRate::query()
                ->where('currency', $currency)
                ->where('effective_from', '<=', now())
                ->max('effective_from');

            if ($newest === null) {
                $stale[(string) $currency] = 'missing entirely';

                continue;
            }

            if (CarbonImmutable::parse((string) $newest)->lessThan($deadline)) {
                $stale[(string) $currency] = 'older than '.$maxAge.' minutes';
            }
        }

        return $stale;
    }

    /**
     * How many wallets do not equal the sum of their own ledger.
     *
     * One aggregate query rather than a walk: this runs on every dashboard
     * render, and the answer should always be zero.
     */
    private function walletDrift(): int
    {
        $rows = DB::select(<<<'SQL'
            SELECT count(*) AS drifted
            FROM users u
            LEFT JOIN (
                SELECT user_id, COALESCE(sum(amount_toman), 0) AS total
                FROM wallet_transactions
                GROUP BY user_id
            ) t ON t.user_id = u.id
            WHERE u.wallet_balance_toman <> COALESCE(t.total, 0)
        SQL);

        return (int) ($rows[0]->drifted ?? 0);
    }
}
