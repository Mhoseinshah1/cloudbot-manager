<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use App\Enums\ImageSelectionMode;
use App\Enums\OrderRefusalReason;
use App\Enums\PurchaseRefusalReason;
use App\Models\Product;
use App\Models\ProductLocationPrice;
use App\Models\ProviderImage;
use App\Orders\Exceptions\PurchaseNotAllowed;
use App\Pricing\Data\PriceQuote;
use App\Telegram\Callbacks\CallbackGrammar;

/**
 * What a customer reads while buying a server.
 *
 * Persian, and deliberately about their purchase rather than about this
 * system. A customer is owed a sentence in their own language, not an internal
 * refusal code and not a phase number.
 *
 * What is shown is the selling price and nothing else. Provider cost, the
 * exchange rate and the margin are all in the quote and none of them belong on
 * a customer's screen: they are what this business pays and earns, and putting
 * them in front of the person being charged is both a commercial mistake and an
 * invitation to argue about a number that is not the price.
 */
final class BuyMessages
{
    public const CHOOSE_LOCATION = 'محل استقرار سرور را انتخاب کنید:';

    public const CHOOSE_IMAGE = 'سیستم‌عامل سرور را انتخاب کنید:';

    public const DEFAULT_IMAGE = 'سیستم‌عامل پیش‌فرض';

    public const ACCEPT_TERMS = 'قوانین را می‌پذیرم';

    public const PAY = 'پرداخت از کیف پول';

    public const CANCEL = 'انصراف';

    public const PREVIOUS = '« قبلی';

    public const NEXT = 'بعدی »';

    public const MAIN_MENU = 'منوی اصلی';

    public const CANCELLED = 'خرید لغو شد.';

    public const NOTHING_FOR_SALE = 'در حال حاضر سروری برای فروش موجود نیست.';

    public const NO_LOCATIONS = 'برای این سرور فعلاً محلی در دسترس نیست.';

    public const NO_IMAGES = 'برای این سرور فعلاً سیستم‌عاملی در دسترس نیست.';

    public const OPTION_GONE = 'این گزینه دیگر در دسترس نیست. لطفاً دوباره انتخاب کنید.';

    public const EXPIRED = 'زمان این خرید به پایان رسید. لطفاً دوباره از منوی اصلی شروع کنید.';

    public const RESTRICTED = 'حساب شما در حال حاضر امکان خرید ندارد. لطفاً با پشتیبانی تماس بگیرید.';

    public const TERMS_MISSING = 'در حال حاضر امکان خرید وجود ندارد. لطفاً بعداً دوباره تلاش کنید.';

    /**
     * Shown when the abuse limits themselves are missing.
     *
     * The customer is told something true without being told what is missing:
     * an unconfigured ceiling is an operator's problem, and describing it would
     * tell whoever is probing exactly which control is absent.
     */
    public const PURCHASES_UNAVAILABLE = 'در حال حاضر امکان ثبت سفارش جدید وجود ندارد. لطفاً بعداً دوباره تلاش کنید.';

    /** Shown when the offer moved between the preview and the confirmation. */
    public const OFFER_CHANGED = 'شرایط این خرید تغییر کرده است. لطفاً اطلاعات جدید را ببینید و در صورت تأیید، دوباره ادامه دهید.';

    /** Shown when a concurrent debit spent the balance mid-purchase. */
    public const SPENT_ELSEWHERE = 'موجودی کیف پول شما برای این خرید کافی نیست. لطفاً موجودی خود را بررسی کنید.';

    public static function chooseProduct(int $page, int $pages): string
    {
        $heading = 'سروری که می‌خواهید بخرید را انتخاب کنید:';

        return $pages > 1 ? $heading."\n\nصفحه {$page} از {$pages}" : $heading;
    }

    public static function productLabel(Product $product): string
    {
        $plan = $product->providerPlan;

        $specification = $plan === null
            ? ''
            : ' — '.$plan->vcpu.' هسته / '.self::gigabytes($plan->ram_mb).' گیگ رم / '.$plan->disk_gb.' گیگ فضا';

        return $product->name.$specification;
    }

    public static function locationLabel(ProductLocationPrice $price): string
    {
        $location = $price->providerLocation;
        $name = $location === null ? '—' : $location->name;

        return $name.' — '.self::money($price->selling_price_toman);
    }

    public static function imageLabel(ProviderImage $image): string
    {
        $version = $image->version === null ? '' : ' '.$image->version;

        return $image->name === '' ? $image->os_family.$version : $image->name;
    }

    /**
     * The offer, exactly as the customer will be charged for it.
     */
    public static function preview(
        ProductLocationPrice $price,
        PriceQuote $quote,
        string $aupVersion,
        ImageSelectionMode $mode,
        string $imageName,
    ): string {
        $product = $price->product;
        $location = $price->providerLocation;

        $chosen = $mode === ImageSelectionMode::Default
            ? $imageName.' (پیش‌فرض)'
            : $imageName;

        return implode("\n", [
            'بررسی نهایی سفارش',
            '',
            'سرور: '.($product === null ? '—' : $product->name),
            'محل: '.($location === null ? '—' : $location->name),
            'سیستم‌عامل: '.$chosen,
            'دوره: ماهانه',
            '',
            'مبلغ قابل پرداخت: '.self::money($quote->sellingPriceToman),
            '',
            'نسخه قوانین و مقررات: '.$aupVersion,
            'با ادامه، شرایط استفاده را می‌پذیرید.',
        ]);
    }

    public static function confirm(int $amountToman, int $balanceToman): string
    {
        return implode("\n", [
            'تأیید پرداخت',
            '',
            'مبلغ: '.self::money($amountToman),
            'موجودی کیف پول: '.self::money($balanceToman),
            '',
            'با تأیید، مبلغ از کیف پول شما کسر و ساخت سرور آغاز می‌شود.',
        ]);
    }

    public static function insufficient(int $balanceToman, int $requiredToman): string
    {
        return implode("\n", [
            'موجودی کیف پول شما کافی نیست.',
            '',
            'موجودی فعلی: '.self::money($balanceToman),
            'مبلغ لازم: '.self::money($requiredToman),
            'کسری: '.self::money(max(0, $requiredToman - $balanceToman)),
        ]);
    }

    public static function ordered(string $orderNumber): string
    {
        return implode("\n", [
            'سفارش شما ثبت شد ✅',
            '',
            'شماره سفارش: '.$orderNumber,
            '',
            'ساخت سرور آغاز شده است. به‌محض آماده شدن، همین‌جا به شما اطلاع می‌دهیم.',
        ]);
    }

    /**
     * Why an abuse control refused, in terms the customer can act on.
     *
     * A limit is only fair if the person hitting it can see it, so the numbers
     * are shown — except for the one case where there is no limit to show,
     * because nobody configured one. That is an operator's problem and the
     * customer is told something true without being told what is missing.
     */
    public static function purchaseBlocked(PurchaseNotAllowed $blocked): string
    {
        return match ($blocked->reason) {
            PurchaseRefusalReason::ActiveServerLimitReached => implode("\n", [
                'شما به حداکثر تعداد سرور مجاز رسیده‌اید.',
                '',
                'سرورهای فعلی: '.$blocked->observed,
                'حداکثر مجاز: '.$blocked->limit,
                '',
                'برای خرید سرور جدید، می‌توانید یکی از سرورهای فعلی را حذف کنید یا با پشتیبانی تماس بگیرید.',
            ]),
            PurchaseRefusalReason::PurchaseVelocityExceeded => 'در بازه زمانی اخیر سفارش‌های زیادی ثبت کرده‌اید. لطفاً کمی بعد دوباره تلاش کنید.',
            PurchaseRefusalReason::LimitsNotConfigured => self::PURCHASES_UNAVAILABLE,
        };
    }

    /**
     * Why the order boundary refused.
     *
     * Deliberately vague where being specific would help nobody: a customer
     * cannot act on "the image belongs to another provider", and reading it
     * would only tell them something about our catalog.
     */
    public static function refusal(OrderRefusalReason $reason): string
    {
        return match ($reason) {
            OrderRefusalReason::InactiveCustomer => self::RESTRICTED,
            OrderRefusalReason::TermsNotConfigured,
            OrderRefusalReason::TermsNotAccepted,
            OrderRefusalReason::TermsVersionMismatch => self::TERMS_MISSING,
            OrderRefusalReason::NoSelectableImage => self::NO_IMAGES,
            OrderRefusalReason::QuoteChanged => self::OFFER_CHANGED,
            default => 'ثبت این سفارش ممکن نشد. لطفاً دوباره از منوی اصلی شروع کنید.',
        };
    }

    /**
     * Why pricing refused.
     *
     * The reason is not shown. A stale exchange rate and a disabled provider
     * are operational facts, and a customer told "the FX rate is too old" has
     * been given our problem to hold.
     */
    public static function saleUnavailable(string $reason): string
    {
        unset($reason);

        return 'در حال حاضر امکان فروش این سرور وجود ندارد. لطفاً بعداً دوباره تلاش کنید.';
    }

    /**
     * @return array<string, string>
     */
    public static function cancelButton(string $token): array
    {
        return ['text' => self::CANCEL, 'callback_data' => CallbackGrammar::buyCancel($token)];
    }

    /**
     * @return array<string, string>
     */
    public static function mainMenuButton(): array
    {
        return ['text' => self::MAIN_MENU, 'callback_data' => CallbackGrammar::mainMenu()];
    }

    /**
     * @return array<string, string>
     */
    public static function walletButton(): array
    {
        return ['text' => 'کیف پول', 'callback_data' => 'menu:wallet'];
    }

    public static function money(int $toman): string
    {
        return number_format($toman).' تومان';
    }

    private static function gigabytes(int $megabytes): string
    {
        $gigabytes = $megabytes / 1024;

        return $gigabytes === floor($gigabytes) ? (string) (int) $gigabytes : number_format($gigabytes, 1);
    }
}
