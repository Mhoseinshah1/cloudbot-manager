<?php

declare(strict_types=1);

namespace App\Orders;

use App\Enums\ServerStatus;
use App\Enums\SettingKey;
use App\Models\Order;
use App\Models\Server;
use App\Models\User;
use App\Orders\Exceptions\PurchaseNotAllowed;
use App\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How many servers one customer may hold, and how fast they may buy them.
 *
 * Automated VPS sales without an abuse ceiling is how a stolen card funds a
 * botnet overnight, so both limits are compulsory: absent or unreadable
 * configuration blocks new purchases rather than defaulting to something
 * generous. Nothing is invented here — a number chosen in code would be a
 * business decision nobody made, applied to every installation.
 *
 * This lives in the order boundary, not in the Telegram flow. A limit enforced
 * only where the buttons are is a limit that disappears the moment a purchase
 * arrives by any other route, and the Telegram check is a courtesy — telling a
 * customer early instead of after they have chosen a server — rather than the
 * control itself.
 */
final readonly class PurchasePolicyService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Refuse a purchase that would break an abuse limit.
     *
     * Call this with the customer's row already locked. Both counts are read
     * from PostgreSQL at that moment, so two purchases racing for the last
     * permitted slot queue behind the lock and the second one sees the first.
     * Checked without the lock, both would see room and both would pass.
     *
     * @throws PurchaseNotAllowed
     */
    public function assertMayPurchase(User $customer): void
    {
        $maximum = $this->requirePositive(SettingKey::AntiAbuseMaxActiveServers);
        $velocity = $this->requirePositive(SettingKey::AntiAbusePurchaseLimitCount);
        $window = $this->requirePositive(SettingKey::AntiAbusePurchaseWindowMinutes);

        $held = $this->capacityUsed($customer);

        if ($held >= $maximum) {
            throw PurchaseNotAllowed::atServerLimit($maximum, $held);
        }

        $recent = $this->ordersCreatedWithin($customer, $window);

        if ($recent >= $velocity) {
            throw PurchaseNotAllowed::tooFast($velocity, $recent, $window);
        }
    }

    /**
     * How many servers this customer is currently taken to hold.
     *
     * Counted conservatively, and the conservatism is the point. A limit that
     * counted only delivered servers would let a customer place ten orders
     * before the first one finished building, pay for all ten, and end up ten
     * servers over the ceiling with nothing left to refuse. So this counts two
     * things:
     *
     *  - every server that is not terminated, whatever state it is in. A
     *    suspended or missing machine still exists as a commitment, and one
     *    that needs attention is exactly the kind somebody should not be
     *    stacking more on top of.
     *  - every order that could still become a server and has not yet. That
     *    deliberately includes orders nobody has paid for. Counting only funded
     *    ones leaves an obvious hole: place three orders while holding none,
     *    each passing a check that sees no commitment, then pay all three. The
     *    velocity limit slows that down and does not close it, because a
     *    patient customer simply waits out the window.
     *
     * A funded order that already has its server is not double-counted: the
     * server itself is in the first count. Orders that can no longer become a
     * server — expired, cancelled, failed, refunded — hold no slot, so a
     * customer whose purchase went wrong is not penalised for it, and one
     * sitting on a stale unpaid order can cancel it to free the slot.
     */
    public function capacityUsed(User $customer): int
    {
        $servers = Server::query()
            ->where('user_id', $customer->getKey())
            ->where('status', '!=', ServerStatus::Terminated->value)
            ->count();

        $owed = Order::query()
            ->where('user_id', $customer->getKey())
            ->whereIn('status', self::OWING_STATUSES)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('servers')
                    ->whereColumn('servers.order_id', 'orders.id');
            })
            ->count();

        return $servers + $owed;
    }

    /**
     * Orders this customer created inside the window, from persisted history.
     *
     * Order rows, not Telegram taps. A customer whose first attempt failed and
     * who tried again has genuinely made two purchases, and a counter that
     * watched button presses could be evaded by any client that stopped
     * pressing buttons.
     */
    public function ordersCreatedWithin(User $customer, int $windowMinutes): int
    {
        return Order::query()
            ->where('user_id', $customer->getKey())
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes($windowMinutes))
            ->count();
    }

    /** The configured ceiling, for showing a customer where they stand. */
    public function maximumServers(): ?int
    {
        try {
            return $this->requirePositive(SettingKey::AntiAbuseMaxActiveServers);
        } catch (PurchaseNotAllowed) {
            return null;
        }
    }

    /**
     * An order that could still turn into a server.
     *
     * The unpaid ones are here on purpose, and so is `needs_attention`: an
     * order stuck between a payment and a provider is the least safe possible
     * moment to let the same customer start another one.
     *
     * @var list<string>
     */
    private const OWING_STATUSES = [
        'pending',
        'awaiting_payment',
        'paid',
        'provisioning',
        'needs_attention',
    ];

    /**
     * A configured limit, or a refusal.
     *
     * Zero and negative are refused along with absent. A ceiling of zero would
     * be a decision to sell nothing, which no operator sets by intent, and a
     * negative one is a typo — reading either as a working limit would silently
     * close the shop or silently open it.
     *
     * @throws PurchaseNotAllowed
     */
    private function requirePositive(SettingKey $key): int
    {
        $value = $this->settings->integer($key);

        if ($value === null || $value <= 0) {
            throw PurchaseNotAllowed::notConfigured($key->value);
        }

        return $value;
    }
}
