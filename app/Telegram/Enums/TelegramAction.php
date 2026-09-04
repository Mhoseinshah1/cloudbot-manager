<?php

declare(strict_types=1);

namespace App\Telegram\Enums;

/**
 * The things a customer can ask for, as this phase understands them.
 *
 * Inbound text and callback data are untrusted strings of arbitrary length.
 * Rather than storing what somebody typed and deciding later what it meant,
 * every update is resolved to one of these cases at the boundary — and if it
 * resolves to none of them, it becomes `Unknown` and the text is discarded.
 *
 * That is the whole reason this enum exists. A closed vocabulary means the
 * database holds a value the system chose, never a value a stranger sent, and
 * an unrecognised message cannot smuggle content into a log, an audit entry or
 * an operator's screen.
 *
 * The menu entries are the Release 1.0 set. Only `/start`, the profile and the
 * help entry do anything in this phase; the rest are recognised so a customer
 * pressing one gets a courteous answer rather than silence.
 */
enum TelegramAction: string
{
    /** The entry point: identify the customer and show the menu. */
    case Start = 'start';

    case MenuBuyServer = 'menu.buy_server';

    case MenuMyServers = 'menu.my_servers';

    case MenuWallet = 'menu.wallet';

    case MenuInvoices = 'menu.invoices';

    case MenuProfile = 'menu.profile';

    case MenuHelp = 'menu.help';

    /** Recognised as a request to go back to the menu. */
    case MainMenu = 'menu.main';

    /*
     * Buying a server.
     *
     * One case per step rather than one "buy" case carrying a step, because the
     * step is what decides whether a customer's tap creates an order. A single
     * case would make "which stage was this?" a question about untrusted data
     * instead of about the closed vocabulary.
     */

    case BuyPage = 'buy.page';

    case BuyProduct = 'buy.product';

    case BuyLocation = 'buy.location';

    case BuyImage = 'buy.image';

    case BuyAcceptTerms = 'buy.accept_terms';

    case BuyConfirm = 'buy.confirm';

    case BuyCancel = 'buy.cancel';

    /*
     * Managing a server.
     */

    case ServerPage = 'server.page';

    case ServerView = 'server.view';

    case ServerPowerOn = 'server.power_on';

    case ServerPowerOff = 'server.power_off';

    case ServerReboot = 'server.reboot';

    case ServerRevealPassword = 'server.reveal_password';

    /** Asks for the confirmation screen. Deletes nothing. */
    case ServerDelete = 'server.delete';

    /** The confirmation itself. The only case that deletes anything. */
    case ServerDeleteConfirm = 'server.delete_confirm';

    /*
     * Wallet and invoices.
     */

    case WalletPage = 'wallet.page';

    case InvoicePage = 'invoice.page';

    case InvoiceView = 'invoice.view';

    /** Something arrived that this phase has no meaning for. */
    case Unknown = 'unknown';

    /**
     * The six entries the main menu offers.
     *
     * Named here so a test can prove every one of them reaches a flow. The menu
     * showed all six from the first phase, with four of them answering that
     * they were not ready; they all work now, and this is what stops one
     * quietly regressing to a polite refusal.
     *
     * @return list<self>
     */
    public static function menuEntries(): array
    {
        return [
            self::MenuBuyServer, self::MenuMyServers, self::MenuWallet,
            self::MenuInvoices, self::MenuProfile, self::MenuHelp,
        ];
    }

    /**
     * Whether this action belongs to the buy flow.
     *
     * Used to decide what a stale tap means: a buy step arriving with no live
     * flow is an expired conversation, which is a different thing from a menu
     * entry, and the customer is told so rather than shown an empty screen.
     */
    public function isBuyStep(): bool
    {
        return in_array($this, [
            self::BuyPage, self::BuyProduct, self::BuyLocation, self::BuyImage,
            self::BuyAcceptTerms, self::BuyConfirm, self::BuyCancel,
        ], strict: true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
