<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every business setting this system reads, named once.
 *
 * A setting is looked up by a string, and a mistyped string reads as "absent"
 * rather than as an error. For a kill switch that is the difference between
 * "sales are off" and "sales are on because nobody noticed the key was
 * `sales.enable`". Declaring them here makes the typo a fatal one.
 *
 * Keys are `<area>.<name>`, matching how permissions are named.
 */
enum SettingKey: string
{
    /**
     * Whether new sales may be quoted at all.
     *
     * The operator's off switch during an incident. Absent or malformed means
     * off: nothing about a missing row says selling is safe.
     */
    case SalesEnabled = 'sales.enabled';

    /**
     * How old an exchange rate may be and still price a new sale, in minutes.
     *
     * Absent or malformed blocks new sales rather than defaulting, because any
     * number invented here would be a business decision made by accident.
     */
    case FxMaxAgeMinutes = 'fx.max_age_minutes';

    /**
     * The acceptable-use terms version a customer must accept to buy.
     *
     * The version string only. The terms themselves are a document, published
     * elsewhere; recording which one a customer agreed to is what an order
     * needs, and inventing the text here would be inventing the policy.
     *
     * Absent or empty means no order can be placed: nobody can accept terms
     * that have not been declared.
     */
    case AupCurrentVersion = 'aup.current_version';

    /**
     * Whether paid orders may be sent to a provider to be built.
     *
     * A separate switch from sales, and deliberately not the same one. Sales
     * decides whether new money may be taken; this decides whether money
     * already taken may be spent at a provider. During an incident an operator
     * usually wants exactly one of those, and a single switch would force them
     * to choose between refusing new customers and stranding paid ones.
     *
     * Absent or malformed means off: nothing about a missing row says it is
     * safe to spend money at a third party. Off is a pause, never a failure —
     * a paid order waits, keeps its token, and resumes when the switch returns.
     */
    case ProvisioningEnabled = 'provisioning.enabled';

    /**
     * How long an order may sit in provisioning before a sweep looks at it,
     * in minutes.
     *
     * Absent or malformed stops automatic sweeping rather than defaulting: any
     * number invented here would decide, by accident, how long a customer waits
     * before anyone notices their server never arrived. An operator can still
     * reconcile a named order by hand.
     */
    case ProvisioningStuckAfterMinutes = 'provisioning.stuck_after_minutes';

    /**
     * How many live servers one customer may hold at once.
     *
     * Absent or malformed blocks new purchases. That is the whole point of a
     * limit: a system that sells without one because nobody configured it is
     * indistinguishable, from the outside, from a system with no limit — and
     * automated VPS sales without an abuse ceiling is how a stolen card funds
     * a botnet. Existing servers stay viewable and manageable either way; only
     * buying more stops.
     *
     * The specification suggests 3 for a new customer. It is not defaulted
     * here: a number invented in code is a business decision nobody made.
     */
    case AntiAbuseMaxActiveServers = 'anti_abuse.max_active_servers';

    /**
     * How many orders one customer may create inside the window below.
     *
     * Counted from persisted orders, not from button presses: a customer whose
     * order failed and who tries again has made two orders, and a rate limit
     * that counted Telegram taps could be evaded by a client that retries.
     *
     * Absent or malformed blocks new purchases, for the same reason as above.
     */
    case AntiAbusePurchaseLimitCount = 'anti_abuse.purchase_limit_count';

    /**
     * How far back the purchase count above looks, in minutes.
     *
     * Absent or malformed blocks new purchases. A window invented here would
     * silently decide how fast someone can buy.
     */
    case AntiAbusePurchaseWindowMinutes = 'anti_abuse.purchase_window_minutes';

    public function type(): SettingType
    {
        return match ($this) {
            self::SalesEnabled, self::ProvisioningEnabled => SettingType::Boolean,
            self::FxMaxAgeMinutes,
            self::ProvisioningStuckAfterMinutes,
            self::AntiAbuseMaxActiveServers,
            self::AntiAbusePurchaseLimitCount,
            self::AntiAbusePurchaseWindowMinutes => SettingType::Integer,
            self::AupCurrentVersion => SettingType::String,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
