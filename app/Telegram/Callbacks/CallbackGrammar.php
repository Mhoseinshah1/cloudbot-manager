<?php

declare(strict_types=1);

namespace App\Telegram\Callbacks;

use App\Telegram\Data\CallbackParameters;
use App\Telegram\Enums\TelegramAction;

/**
 * The closed grammar this system will read out of a pressed button.
 *
 * Callback data is untrusted input that happens to have been round-tripped
 * through Telegram. It is not signed, it is not private, and a customer can
 * send whatever they like in it — so this parses rather than trusts: a fixed
 * set of verbs, a fixed arity per verb, a bounded token, a bounded integer.
 * Anything that does not fit exactly becomes `Unknown` and the data is
 * discarded.
 *
 * What comes out is still not authority. An id here means "the customer is
 * asking about this record", and every lookup that follows is scoped by owner
 * in the query. That separation is the whole point: the grammar decides what
 * was asked, the database decides whether they may have it.
 *
 * Shapes:
 *
 *     menu:main                    a fixed entry, no parameters
 *     buy:p:{flow}:{id}            product chosen, inside this flow
 *     buy:l:{flow}:{id}            location chosen
 *     buy:i:{flow}:{id}            image chosen
 *     buy:i:{flow}:d               the location's default image
 *     buy:pg:{flow}:{page}         another page of products
 *     buy:aup:{flow}               terms accepted
 *     buy:ok:{flow}                pay and order
 *     buy:x:{flow}                 give up
 *     srv:pg:{page}                another page of servers
 *     srv:v:{id}                   open one
 *     srv:on:{id} / off / rb       power and reboot
 *     srv:pw:{id}                  reveal the root password
 *     srv:del:{id}                 ask for confirmation; deletes nothing
 *     srv:delok:{flow}             confirm; the only shape that deletes
 *     wal:pg:{page}                wallet history
 *     inv:pg:{page} / inv:v:{id}   invoices
 *
 * Every one of these fits inside Telegram's 64-byte callback limit with room
 * for the largest id this database can produce.
 */
final class CallbackGrammar
{
    /** Telegram's own limit on callback data, and therefore ours. */
    public const MAX_LENGTH = 64;

    /** What a customer sends to mean "whatever the default is". */
    private const DEFAULT_MARKER = 'd';

    /** Fixed entries: no parameters, matched whole. */
    private const FIXED = [
        'menu:main' => TelegramAction::MainMenu,
        'menu:buy_server' => TelegramAction::MenuBuyServer,
        'menu:my_servers' => TelegramAction::MenuMyServers,
        'menu:wallet' => TelegramAction::MenuWallet,
        'menu:invoices' => TelegramAction::MenuInvoices,
        'menu:profile' => TelegramAction::MenuProfile,
        'menu:help' => TelegramAction::MenuHelp,
    ];

    /**
     * Verbs that take a flow token and one integer.
     *
     * @var array<string, TelegramAction>
     */
    private const FLOW_AND_ID = [
        'buy:p' => TelegramAction::BuyProduct,
        'buy:l' => TelegramAction::BuyLocation,
        'buy:i' => TelegramAction::BuyImage,
    ];

    /**
     * Verbs that take a flow token and a page number.
     *
     * @var array<string, TelegramAction>
     */
    private const FLOW_AND_PAGE = [
        'buy:pg' => TelegramAction::BuyPage,
    ];

    /**
     * Verbs that take a flow token and nothing else.
     *
     * @var array<string, TelegramAction>
     */
    private const FLOW_ONLY = [
        'buy:aup' => TelegramAction::BuyAcceptTerms,
        'buy:ok' => TelegramAction::BuyConfirm,
        'buy:x' => TelegramAction::BuyCancel,
        // The confirmation carries only a token, never a server id. The id is
        // read from the delete intent this system wrote, so a stale keyboard
        // cannot aim a week-old confirmation at a server chosen since.
        'srv:delok' => TelegramAction::ServerDeleteConfirm,
    ];

    /**
     * Verbs that take one integer id.
     *
     * @var array<string, TelegramAction>
     */
    private const ID_ONLY = [
        'srv:v' => TelegramAction::ServerView,
        'srv:on' => TelegramAction::ServerPowerOn,
        'srv:off' => TelegramAction::ServerPowerOff,
        'srv:rb' => TelegramAction::ServerReboot,
        'srv:pw' => TelegramAction::ServerRevealPassword,
        'srv:del' => TelegramAction::ServerDelete,
        'inv:v' => TelegramAction::InvoiceView,
    ];

    /**
     * Verbs that take one page number.
     *
     * @var array<string, TelegramAction>
     */
    private const PAGE_ONLY = [
        'srv:pg' => TelegramAction::ServerPage,
        'wal:pg' => TelegramAction::WalletPage,
        'inv:pg' => TelegramAction::InvoicePage,
    ];

    /**
     * What this button asked for, and the safe hints it carried.
     *
     * @return array{action: TelegramAction, parameters: CallbackParameters}
     */
    public static function parse(?string $data): array
    {
        if ($data === null) {
            return self::unknown();
        }

        $trimmed = trim($data);

        // Bounded before anything is split. Telegram will not send more than
        // this, so a longer payload did not come from a keyboard we drew.
        if ($trimmed === '' || strlen($trimmed) > self::MAX_LENGTH) {
            return self::unknown();
        }

        if (array_key_exists($trimmed, self::FIXED)) {
            return ['action' => self::FIXED[$trimmed], 'parameters' => CallbackParameters::none()];
        }

        $parts = explode(':', $trimmed);

        // Every dynamic shape is three or four segments. Anything else is not
        // one of ours, whatever it starts with.
        if (count($parts) < 3 || count($parts) > 4) {
            return self::unknown();
        }

        $verb = $parts[0].':'.$parts[1];

        if (count($parts) === 4) {
            return self::withFlowAndArgument($verb, $parts[2], $parts[3]);
        }

        return self::withOneArgument($verb, $parts[2]);
    }

    /**
     * `verb:{flow}:{argument}` — the buy flow's stepped shapes.
     *
     * @return array{action: TelegramAction, parameters: CallbackParameters}
     */
    private static function withFlowAndArgument(string $verb, string $flow, string $argument): array
    {
        $token = self::token($flow);

        if ($token === null) {
            return self::unknown();
        }

        if (array_key_exists($verb, self::FLOW_AND_ID)) {
            // The image step alone accepts a marker instead of an id, because
            // "the default" is a real choice and not the absence of one.
            if ($verb === 'buy:i' && $argument === self::DEFAULT_MARKER) {
                return [
                    'action' => TelegramAction::BuyImage,
                    'parameters' => new CallbackParameters(flowToken: $token, wantsDefault: true),
                ];
            }

            $id = self::identifier($argument);

            return $id === null
                ? self::unknown()
                : ['action' => self::FLOW_AND_ID[$verb], 'parameters' => new CallbackParameters(id: $id, flowToken: $token)];
        }

        if (array_key_exists($verb, self::FLOW_AND_PAGE)) {
            $page = self::page($argument);

            return $page === null
                ? self::unknown()
                : ['action' => self::FLOW_AND_PAGE[$verb], 'parameters' => new CallbackParameters(page: $page, flowToken: $token)];
        }

        return self::unknown();
    }

    /**
     * `verb:{argument}` — one id, one page, or one flow token.
     *
     * @return array{action: TelegramAction, parameters: CallbackParameters}
     */
    private static function withOneArgument(string $verb, string $argument): array
    {
        if (array_key_exists($verb, self::FLOW_ONLY)) {
            $token = self::token($argument);

            return $token === null
                ? self::unknown()
                : ['action' => self::FLOW_ONLY[$verb], 'parameters' => new CallbackParameters(flowToken: $token)];
        }

        if (array_key_exists($verb, self::ID_ONLY)) {
            $id = self::identifier($argument);

            return $id === null
                ? self::unknown()
                : ['action' => self::ID_ONLY[$verb], 'parameters' => new CallbackParameters(id: $id)];
        }

        if (array_key_exists($verb, self::PAGE_ONLY)) {
            $page = self::page($argument);

            return $page === null
                ? self::unknown()
                : ['action' => self::PAGE_ONLY[$verb], 'parameters' => new CallbackParameters(page: $page)];
        }

        return self::unknown();
    }

    /** A flow token: lowercase hex, bounded, or nothing. */
    private static function token(string $value): ?string
    {
        return preg_match('/^[0-9a-f]{4,'.CallbackParameters::MAX_TOKEN.'}$/', $value) === 1 ? $value : null;
    }

    /**
     * A positive record id.
     *
     * Bounded to what a signed 64-bit key can hold, so a number too large to be
     * a row is refused here rather than becoming a database error later.
     */
    private static function identifier(string $value): ?int
    {
        if (preg_match('/^[1-9]\d{0,18}$/', $value) !== 1) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /** A page number: at least one, and nothing absurd. */
    private static function page(string $value): ?int
    {
        if (preg_match('/^[1-9]\d{0,5}$/', $value) !== 1) {
            return null;
        }

        $page = (int) $value;

        return $page >= 1 && $page <= CallbackParameters::MAX_PAGE ? $page : null;
    }

    /**
     * @return array{action: TelegramAction, parameters: CallbackParameters}
     */
    private static function unknown(): array
    {
        return ['action' => TelegramAction::Unknown, 'parameters' => CallbackParameters::none()];
    }

    /*
     * Building the data a keyboard carries.
     *
     * Written here beside the parser on purpose: a grammar whose two halves
     * live apart drifts, and the direction it drifts in is buttons that no
     * longer parse.
     */

    public static function buyProduct(string $flow, int $id): string
    {
        return "buy:p:{$flow}:{$id}";
    }

    public static function buyLocation(string $flow, int $id): string
    {
        return "buy:l:{$flow}:{$id}";
    }

    public static function buyImage(string $flow, int $id): string
    {
        return "buy:i:{$flow}:{$id}";
    }

    public static function buyDefaultImage(string $flow): string
    {
        return 'buy:i:'.$flow.':'.self::DEFAULT_MARKER;
    }

    public static function buyPage(string $flow, int $page): string
    {
        return "buy:pg:{$flow}:{$page}";
    }

    public static function buyAcceptTerms(string $flow): string
    {
        return "buy:aup:{$flow}";
    }

    public static function buyConfirm(string $flow): string
    {
        return "buy:ok:{$flow}";
    }

    public static function buyCancel(string $flow): string
    {
        return "buy:x:{$flow}";
    }

    public static function serverPage(int $page): string
    {
        return "srv:pg:{$page}";
    }

    public static function serverView(int $id): string
    {
        return "srv:v:{$id}";
    }

    public static function serverPowerOn(int $id): string
    {
        return "srv:on:{$id}";
    }

    public static function serverPowerOff(int $id): string
    {
        return "srv:off:{$id}";
    }

    public static function serverReboot(int $id): string
    {
        return "srv:rb:{$id}";
    }

    public static function serverRevealPassword(int $id): string
    {
        return "srv:pw:{$id}";
    }

    public static function serverDelete(int $id): string
    {
        return "srv:del:{$id}";
    }

    public static function serverDeleteConfirm(string $flow): string
    {
        return "srv:delok:{$flow}";
    }

    public static function walletPage(int $page): string
    {
        return "wal:pg:{$page}";
    }

    public static function invoicePage(int $page): string
    {
        return "inv:pg:{$page}";
    }

    public static function invoiceView(int $id): string
    {
        return "inv:v:{$id}";
    }

    public static function mainMenu(): string
    {
        return 'menu:main';
    }
}
