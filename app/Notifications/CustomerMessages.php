<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;

/**
 * What a customer is told when something happens to their order or server.
 *
 * Persian, like everything else they see, and assembled from facts this system
 * already holds. No provider text is ever interpolated: a provider's message is
 * written for us, not for them, and it quotes back requests that carry
 * credentials.
 *
 * A root password never appears in any of these. It is delivered once, through
 * a deliberate and audited reveal, and a notification that included it would
 * put a credential into a message the customer keeps forever.
 */
final class CustomerMessages
{
    /**
     * @param  array<string, mixed>  $facts
     */
    public static function provisioningSucceeded(array $facts): string
    {
        $lines = ['سرور شما آماده است ✅', ''];

        $lines[] = 'شماره سفارش: '.self::text($facts, 'order_number');
        $lines[] = 'نام سرور: '.self::text($facts, 'server_name');

        $ipv4 = self::optional($facts, 'ip_address');

        if ($ipv4 !== null) {
            $lines[] = 'آدرس IPv4: '.$ipv4;
        }

        $ipv6 = self::optional($facts, 'ipv6_address');

        if ($ipv6 !== null) {
            $lines[] = 'آدرس IPv6: '.$ipv6;
        }

        $until = self::optional($facts, 'current_period_end');

        if ($until !== null) {
            $lines[] = 'اعتبار سرویس تا: '.$until;
        }

        $lines[] = '';
        $lines[] = 'برای مدیریت سرور، از بخش «سرورهای من» استفاده کنید.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function orderRefunded(array $facts): string
    {
        return implode("\n", [
            'سفارش شما تکمیل نشد و مبلغ آن به کیف پول شما بازگردانده شد.',
            '',
            'شماره سفارش: '.self::text($facts, 'order_number'),
            'مبلغ بازگشتی: '.self::money($facts, 'amount_toman'),
            '',
            'موجودی کیف پول شما در بخش «کیف پول» قابل مشاهده است.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function serverTerminated(array $facts): string
    {
        return implode("\n", [
            'سرور شما حذف شد.',
            '',
            'نام سرور: '.self::text($facts, 'server_name'),
            '',
            'سرویس این سرور از همین لحظه پایان یافته است. سوابق فاکتورهای شما محفوظ می‌ماند.',
        ]);
    }

    /**
     * The one message that carries a credential, and the only one.
     *
     * Sent on its own, never merged into a details screen, and followed by a
     * best-effort deletion. The warning matters more than the deletion does:
     * Telegram may refuse to delete, the customer may have forwarded it
     * already, and a password that has been on a phone screen is a password
     * that should be changed.
     */
    public static function rootPassword(string $serverName, string $password, int $visibleSeconds): string
    {
        return implode("\n", [
            'رمز عبور root سرور «'.$serverName.'»:',
            '',
            $password,
            '',
            'این پیام تا حدود '.$visibleSeconds.' ثانیه دیگر حذف می‌شود.',
            'لطفاً همین حالا آن را در جای امنی ذخیره و پس از ورود، تغییرش دهید.',
        ]);
    }

    /**
     * A monthly period is about to end.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function subscriptionExpiryWarning(array $facts): string
    {
        return implode("\n", [
            'سرویس سرور شما به‌زودی به پایان می‌رسد.',
            '',
            'نام سرور: '.self::text($facts, 'server_name'),
            'زمان پایان: '.self::moment($facts, 'expires_at'),
            'زمان باقی‌مانده: '.self::days($facts, 'days_left'),
            '',
            'برای ادامه سرویس، از بخش «سرورهای من» گزینه تمدید را انتخاب کنید.',
        ]);
    }

    /**
     * The period ended, and the machine is still recoverable.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function subscriptionGraceEntered(array $facts): string
    {
        return implode("\n", [
            'سرویس سرور شما به پایان رسید.',
            '',
            'نام سرور: '.self::text($facts, 'server_name'),
            'پایان سرویس: '.self::moment($facts, 'expired_at'),
            'مهلت تمدید تا: '.self::moment($facts, 'grace_until'),
            '',
            'ممکن است سرور شما خاموش شده باشد. تا پایان مهلت بالا امکان تمدید و بازگشت سرویس وجود دارد.',
        ]);
    }

    /**
     * What happens if the grace window closes. Sent before anything is deleted.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function subscriptionTerminationWarning(array $facts): string
    {
        return implode("\n", [
            '⚠️ هشدار حذف سرور',
            '',
            'نام سرور: '.self::text($facts, 'server_name'),
            'مهلت تمدید تا: '.self::moment($facts, 'grace_until'),
            '',
            'در صورت عدم تمدید تا زمان بالا، سرور و تمام اطلاعات آن برای همیشه حذف خواهد شد و قابل بازگردانی نیست.',
            'برای جلوگیری از حذف، از بخش «سرورهای من» سرویس را تمدید کنید.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public static function subscriptionRenewed(array $facts): string
    {
        return implode("\n", [
            'سرویس سرور شما تمدید شد.',
            '',
            'نام سرور: '.self::text($facts, 'server_name'),
            'مبلغ: '.self::money($facts, 'amount_toman'),
            'پایان سرویس جدید: '.self::moment($facts, 'new_period_end'),
        ]);
    }

    /**
     * An instant, shown in the customer's timezone.
     *
     * Storage and every comparison stay UTC; this is display only. A customer
     * told their server expires at 20:30 needs that to be 20:30 where they are,
     * or the warning is worse than none.
     *
     * @param  array<string, mixed>  $facts
     */
    private static function moment(array $facts, string $key): string
    {
        $value = $facts[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return '—';
        }

        try {
            $instant = CarbonImmutable::parse($value)
                ->setTimezone((string) config('cloudbot.customer_timezone', 'UTC'));
        } catch (\Throwable) {
            return '—';
        }

        return $instant->format('Y-m-d H:i');
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private static function days(array $facts, string $key): string
    {
        $value = $facts[$key] ?? null;

        return is_int($value) && $value > 0 ? $value.' روز' : '—';
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private static function text(array $facts, string $key): string
    {
        return self::optional($facts, $key) ?? '—';
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private static function money(array $facts, string $key): string
    {
        $value = $facts[$key] ?? null;

        return is_int($value) ? number_format($value).' تومان' : '—';
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private static function optional(array $facts, string $key): ?string
    {
        $value = $facts[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
