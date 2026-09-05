<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Enums\UserStatus;

/**
 * What the customer sees.
 *
 * Persian-first, because the customers are. The six Release 1.0 entries were
 * all shown from the first phase, so the shape of the product was honest rather
 * than growing buttons one at a time; four of them answered that they were not
 * ready yet. All six work now, and that answer is gone with them.
 *
 * Nothing here says "Phase 9". A customer is owed a sentence in their own
 * language, not this project's internal schedule.
 *
 * The keyboard carries labels only. No price, no identifier, no claim about who
 * owns what: a button is a request, and what a customer is allowed to do is
 * decided from their account when the request arrives, never from what the
 * button said.
 */
final class MainMenu
{
    public const GREETING = 'به ربات فروش سرور خوش آمدید 👋';

    public const PROMPT = 'برای شروع، یکی از گزینه‌های زیر را انتخاب کنید:';

    /** Shown when a conversation was forgotten before it finished. */
    public const STATE_EXPIRED = 'زمان این گفتگو به پایان رسید. لطفاً دوباره از منوی اصلی شروع کنید.';

    /** Shown for text nobody has a handler for. */
    public const UNKNOWN = 'متوجه نشدم. لطفاً از دکمه‌های منو استفاده کنید.';

    /** Shown when a pressed button no longer means anything. */
    public const CALLBACK_EXPIRED = 'این گزینه دیگر معتبر نیست.';

    public const HELP = <<<'TEXT'
        راهنما

        این ربات برای خرید و مدیریت سرور مجازی است.

        • برای بازگشت به منوی اصلی دستور /menu را بفرستید.
        • برای شروع دوباره دستور /start را بفرستید.

        در صورت نیاز به پشتیبانی، از همین گفتگو پیام بدهید.
        TEXT;

    /** Shown to an account that is suspended or banned. */
    public const RESTRICTED = 'حساب شما در حال حاضر فعال نیست. لطفاً با پشتیبانی تماس بگیرید.';

    /**
     * The menu itself.
     *
     * A reply keyboard rather than inline buttons: it stays visible under the
     * message box, which is what a customer returning to the bot after a week
     * needs, and it carries no callback data to be tampered with at all.
     *
     * @return array<string, mixed>
     */
    public static function keyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => 'خرید سرور'], ['text' => 'سرورهای من']],
                [['text' => 'کیف پول'], ['text' => 'فاکتورها']],
                [['text' => 'پروفایل'], ['text' => 'راهنما']],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    /** The greeting a customer sees on `/start`. */
    public static function welcome(): string
    {
        return self::GREETING."\n\n".self::PROMPT;
    }

    /**
     * A short description of the account, for the profile entry.
     *
     * Deliberately spare: an identifier the customer can quote to support, and
     * nothing about balances or servers, which belong to the entries that own
     * them.
     */
    public static function profile(int $telegramUserId, UserStatus $status): string
    {
        $line = $status === UserStatus::Active
            ? 'وضعیت حساب: فعال'
            : self::RESTRICTED;

        return "پروفایل شما\n\nشناسه کاربری: {$telegramUserId}\n{$line}";
    }
}
