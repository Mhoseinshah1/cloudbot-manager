<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use App\Enums\WalletTransactionType;
use App\Models\WalletTransaction;
use App\Telegram\Callbacks\CallbackGrammar;
use App\Telegram\TelegramApiClient;

/**
 * The customer's balance, and where it went.
 *
 * Read only, in every sense. Nothing here moves money: the wallet has one
 * mutation authority and it is not a chat interface. A Telegram flow that could
 * credit a balance would be a Telegram flow that could be made to credit a
 * balance.
 *
 * Every query is scoped by customer before it is scoped by anything else, so a
 * page number cannot become a way of reading somebody else's ledger.
 *
 * Release 1.0 has no automated top-up. Rather than invent one or dress up the
 * manual finance path as a payment gateway, the screen says plainly that
 * topping up is not available here — which is true, and lets a customer ask
 * support instead of waiting for a button that will never work.
 */
final readonly class WalletFlow
{
    private const PER_PAGE = 8;

    public function __construct(private TelegramApiClient $telegram) {}

    public function show(FlowContext $context, int $page = 1): void
    {
        $customer = $context->customer->fresh() ?? $context->customer;

        $history = WalletTransaction::query()
            ->where('user_id', $customer->getKey())
            ->orderByDesc('id')
            ->paginate(perPage: self::PER_PAGE, page: max(1, $page));

        $lines = [
            'کیف پول شما',
            '',
            'موجودی: '.BuyMessages::money($customer->wallet_balance_toman),
        ];

        if ($history->total() === 0) {
            $lines[] = '';
            $lines[] = 'هنوز تراکنشی ثبت نشده است.';
        } else {
            $lines[] = '';
            $lines[] = 'آخرین تراکنش‌ها:';

            foreach ($history->items() as $transaction) {
                if ($transaction instanceof WalletTransaction) {
                    $lines[] = self::line($transaction);
                }
            }

            if ($history->lastPage() > 1) {
                $lines[] = '';
                $lines[] = 'صفحه '.$history->currentPage().' از '.$history->lastPage();
            }
        }

        $lines[] = '';
        // Honest about what is not here. No fake instant top-up, and no
        // presenting internal finance tooling as a payment gateway.
        $lines[] = 'افزایش موجودی از طریق ربات در حال حاضر فعال نیست. برای شارژ کیف پول با پشتیبانی در تماس باشید.';

        $navigation = [];

        if ($history->currentPage() > 1) {
            $navigation[] = [
                'text' => BuyMessages::PREVIOUS,
                'callback_data' => CallbackGrammar::walletPage($history->currentPage() - 1),
            ];
        }

        if ($history->currentPage() < $history->lastPage()) {
            $navigation[] = [
                'text' => BuyMessages::NEXT,
                'callback_data' => CallbackGrammar::walletPage($history->currentPage() + 1),
            ];
        }

        $keyboard = $navigation === [] ? [] : [$navigation];
        $keyboard[] = [BuyMessages::mainMenuButton()];

        $this->telegram->sendMessage($context->chatId, implode("\n", $lines), [
            'inline_keyboard' => $keyboard,
        ]);
    }

    /**
     * One ledger line: the sign, the amount, and what it was for.
     *
     * The description is the short phrase this system wrote when the movement
     * happened. The metadata is not shown at all — it is an operational blob,
     * and a customer's screen is not where an unbounded structure should be
     * rendered.
     */
    private static function line(WalletTransaction $transaction): string
    {
        $sign = match ($transaction->type) {
            WalletTransactionType::Credit, WalletTransactionType::Refund => '+',
            WalletTransactionType::Debit => '−',
            WalletTransactionType::Adjustment => $transaction->amount_toman < 0 ? '−' : '+',
        };

        $amount = BuyMessages::money(abs($transaction->amount_toman));
        $when = $transaction->created_at->toDateString();

        return "• {$when} — {$sign}{$amount} — {$transaction->description}";
    }
}
