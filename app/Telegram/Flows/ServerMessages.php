<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use App\Enums\ServerActionType;
use App\Enums\ServerPowerState;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Servers\Exceptions\ServerActionNotAllowed;
use App\Servers\Exceptions\ServerActionRefusal;

/**
 * What a customer reads about their servers.
 *
 * The root password never appears in any of this. Not in the list, not in the
 * details, not in a confirmation. It has exactly one route to a customer — a
 * deliberate, audited reveal in a message of its own — and a details screen
 * that happened to include it would put a credential into every screenshot
 * anybody ever takes of their server.
 *
 * Provider metadata is not shown either. It is a whitelisted blob kept for
 * operators, and rendering it would hand a customer whatever a provider
 * happened to put in it.
 */
final class ServerMessages
{
    public const HEADING = 'سرورهای شما';

    public const NONE = 'هنوز سروری ندارید.';

    public const BUY = 'خرید سرور';

    public const BACK = 'بازگشت به سرور';

    public const BACK_TO_LIST = 'فهرست سرورها';

    public const NOT_FOUND = 'سروری با این مشخصات پیدا نشد.';

    public const POWER_ON = 'روشن کردن';

    public const POWER_OFF = 'خاموش کردن';

    public const REBOOT = 'راه‌اندازی مجدد';

    public const PASSWORD = 'نمایش رمز root';

    public const DELETE = 'حذف سرور';

    public const DELETE_CONFIRM = 'بله، حذف کن';

    public const KEEP = 'انصراف';

    public const NO_PASSWORD = 'برای این سرور رمز عبوری ذخیره نشده است.';

    public const DELETE_EXPIRED = 'زمان تأیید حذف به پایان رسید. لطفاً دوباره از فهرست سرورها اقدام کنید.';

    public const DELETE_REQUESTED = 'درخواست حذف ثبت شد. پس از حذف شدن سرور، به شما اطلاع می‌دهیم.';

    public static function summary(Server $server): string
    {
        return '• '.$server->name.' — '.self::status($server->status)
            .' / '.self::power($server->power_state)
            .($server->ip_address === null ? '' : ' — '.$server->ip_address);
    }

    /**
     * One server, from local records only.
     */
    public static function details(Server $server): string
    {
        $lines = [
            $server->name,
            '',
            'وضعیت: '.self::status($server->status),
            'روشن/خاموش: '.self::power($server->power_state),
        ];

        if ($server->ip_address !== null) {
            $lines[] = 'آدرس IPv4: '.$server->ip_address;
        }

        if ($server->ipv6_address !== null) {
            $lines[] = 'آدرس IPv6: '.$server->ipv6_address;
        }

        $plan = $server->plan_snapshot;

        if (is_array($plan) && isset($plan['name']) && is_string($plan['name'])) {
            $lines[] = 'پلن: '.$plan['name'];
        }

        if ($server->datacenter !== null) {
            $lines[] = 'محل: '.$server->datacenter;
        }

        $subscription = $server->subscription;

        if ($subscription !== null) {
            $lines[] = 'اعتبار سرویس تا: '.$subscription->current_period_end->toDateString();
            $lines[] = self::remaining($subscription->current_period_end->getTimestamp());
        }

        return implode("\n", $lines);
    }

    public static function deleteWarning(Server $server): string
    {
        return implode("\n", [
            '⚠️ حذف سرور «'.$server->name.'»',
            '',
            'این کار برگشت‌پذیر نیست. تمام اطلاعات روی سرور از بین می‌رود.',
            'سرویس این سرور بلافاصله پایان می‌یابد و مبلغ باقی‌مانده دوره بازگردانده نمی‌شود.',
            '',
            'آیا مطمئن هستید؟',
        ]);
    }

    public static function requested(ServerActionType $action): string
    {
        return match ($action) {
            ServerActionType::PowerOn => 'درخواست روشن کردن سرور ثبت شد.',
            ServerActionType::PowerOff => 'درخواست خاموش کردن سرور ثبت شد.',
            ServerActionType::Reboot => 'درخواست راه‌اندازی مجدد ثبت شد.',
            ServerActionType::Delete => self::DELETE_REQUESTED,
            ServerActionType::RootPasswordReveal => 'رمز عبور برای شما ارسال شد.',
        };
    }

    /**
     * Why an action was refused.
     *
     * A server that is not theirs and a server that does not exist get the same
     * sentence. Anything else would let somebody map our ids by watching which
     * message came back.
     */
    public static function refusal(ServerActionNotAllowed $refused): string
    {
        return match ($refused->refusal) {
            ServerActionRefusal::NoSuchServer => self::NOT_FOUND,
            ServerActionRefusal::InactiveCustomer => 'حساب شما در حال حاضر امکان مدیریت سرور ندارد. لطفاً با پشتیبانی تماس بگیرید.',
            ServerActionRefusal::CapabilityUnsupported => 'این عملیات برای این سرور در دسترس نیست.',
            ServerActionRefusal::ServerNotLive => 'این سرور دیگر فعال نیست.',
            ServerActionRefusal::NoPasswordHeld => self::NO_PASSWORD,
        };
    }

    private static function status(ServerStatus $status): string
    {
        return match ($status) {
            ServerStatus::Active => 'فعال',
            ServerStatus::Suspended => 'معلق',
            ServerStatus::Terminated => 'حذف شده',
            ServerStatus::Missing => 'در دسترس نیست',
            ServerStatus::NeedsAttention => 'در حال بررسی',
        };
    }

    private static function power(ServerPowerState $state): string
    {
        return match ($state) {
            ServerPowerState::On => 'روشن',
            ServerPowerState::Off => 'خاموش',
            ServerPowerState::Unknown => 'نامشخص',
        };
    }

    /**
     * How long is left, as presentation only.
     *
     * The subscription's `current_period_end` remains the authoritative expiry;
     * this is a sentence derived from it, never a second place that decides.
     */
    private static function remaining(int $endsAt): string
    {
        $seconds = $endsAt - time();

        if ($seconds <= 0) {
            return 'زمان باقی‌مانده: پایان یافته';
        }

        $days = intdiv($seconds, 86_400);

        if ($days >= 1) {
            return 'زمان باقی‌مانده: حدود '.$days.' روز';
        }

        return 'زمان باقی‌مانده: کمتر از یک روز';
    }
}
