<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Telegram\Callbacks\CallbackGrammar;
use App\Telegram\TelegramApiClient;

/**
 * What the customer was charged, and for what.
 *
 * Scoped by customer in the query, always. An invoice number is quoted to
 * support and printed on things, so an id arriving in a callback is exactly the
 * kind of value somebody will try incrementing — and the answer to a stranger's
 * id and to a number that does not exist is deliberately the same.
 *
 * Only the fields a customer needs. The pricing snapshot on an invoice is an
 * internal record of cost, rate and margin; rendering it would put this
 * business's economics on the customer's screen.
 */
final readonly class InvoiceFlow
{
    private const PER_PAGE = 8;

    public function __construct(private TelegramApiClient $telegram) {}

    public function list(FlowContext $context, int $page = 1): void
    {
        $invoices = $context->customer->invoices()
            ->with('order')
            ->orderByDesc('id')
            ->paginate(perPage: self::PER_PAGE, page: max(1, $page));

        if ($invoices->total() === 0) {
            $this->telegram->sendMessage($context->chatId, 'هنوز فاکتوری برای شما صادر نشده است.', [
                'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
            ]);

            return;
        }

        $lines = ['فاکتورهای شما', ''];
        $buttons = [];

        foreach ($invoices->items() as $invoice) {
            if (! $invoice instanceof Invoice) {
                continue;
            }

            $lines[] = self::line($invoice);
            $buttons[] = [[
                'text' => $invoice->number,
                'callback_data' => CallbackGrammar::invoiceView((int) $invoice->getKey()),
            ]];
        }

        if ($invoices->lastPage() > 1) {
            $lines[] = '';
            $lines[] = 'صفحه '.$invoices->currentPage().' از '.$invoices->lastPage();
        }

        $navigation = [];

        if ($invoices->currentPage() > 1) {
            $navigation[] = [
                'text' => BuyMessages::PREVIOUS,
                'callback_data' => CallbackGrammar::invoicePage($invoices->currentPage() - 1),
            ];
        }

        if ($invoices->currentPage() < $invoices->lastPage()) {
            $navigation[] = [
                'text' => BuyMessages::NEXT,
                'callback_data' => CallbackGrammar::invoicePage($invoices->currentPage() + 1),
            ];
        }

        if ($navigation !== []) {
            $buttons[] = $navigation;
        }

        $buttons[] = [BuyMessages::mainMenuButton()];

        $this->telegram->sendMessage($context->chatId, implode("\n", $lines), [
            'inline_keyboard' => $buttons,
        ]);
    }

    public function view(FlowContext $context, int $invoiceId): void
    {
        // Scoped by owner in the query. Not a global find with a check
        // afterwards, which is the same thing only until somebody forgets.
        $invoice = $context->customer->invoices()->with('order')->whereKey($invoiceId)->first();

        if (! $invoice instanceof Invoice) {
            // The same answer as for an invoice that does not exist. Two
            // different answers would make this a way of discovering ids.
            $this->telegram->sendMessage($context->chatId, 'فاکتوری با این مشخصات پیدا نشد.', [
                'inline_keyboard' => [[BuyMessages::mainMenuButton()]],
            ]);

            return;
        }

        $lines = [
            'فاکتور '.$invoice->number,
            '',
            'نوع: '.self::type($invoice->type),
            'مبلغ: '.BuyMessages::money($invoice->amount_toman),
            'وضعیت: '.self::status($invoice->status),
            'تاریخ صدور: '.$invoice->issued_at->toDateString(),
        ];

        if ($invoice->order !== null) {
            $lines[] = 'شماره سفارش: '.$invoice->order->order_number;
        }

        $this->telegram->sendMessage($context->chatId, implode("\n", $lines), [
            'inline_keyboard' => [
                [['text' => 'فهرست فاکتورها', 'callback_data' => CallbackGrammar::invoicePage(1)]],
                [BuyMessages::mainMenuButton()],
            ],
        ]);
    }

    private static function line(Invoice $invoice): string
    {
        return '• '.$invoice->number.' — '.BuyMessages::money($invoice->amount_toman)
            .' — '.self::status($invoice->status)
            .' — '.$invoice->issued_at->toDateString();
    }

    private static function type(InvoiceType $type): string
    {
        return match ($type) {
            InvoiceType::ServerPurchase => 'خرید سرور',
            InvoiceType::WalletTopUp => 'افزایش موجودی کیف پول',
        };
    }

    private static function status(InvoiceStatus $status): string
    {
        return match ($status) {
            InvoiceStatus::Issued => 'صادر شده',
        };
    }
}
