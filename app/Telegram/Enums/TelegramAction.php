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

    /** Something arrived that this phase has no meaning for. */
    case Unknown = 'unknown';

    /**
     * Whether the customer-facing behaviour behind this entry exists yet.
     *
     * The menu deliberately shows the full Release 1.0 set from the start, so
     * the shape of the product is honest. The four commerce entries arrive with
     * the sales phase; until then they answer politely instead of pretending.
     */
    public function isImplemented(): bool
    {
        return in_array($this, [
            self::Start, self::MenuProfile, self::MenuHelp, self::MainMenu, self::Unknown,
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
