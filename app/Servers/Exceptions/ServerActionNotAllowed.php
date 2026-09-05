<?php

declare(strict_types=1);

namespace App\Servers\Exceptions;

use App\Enums\ServerActionType;
use RuntimeException;

/**
 * A server action was refused before anything reached a provider.
 *
 * The reason is an enum because the Telegram flow has to decide what to say,
 * and because one of these reasons must be said carefully: a customer naming
 * somebody else's server gets the same answer as one naming a server that does
 * not exist. Distinguishing them would turn the bot into a way of discovering
 * which ids are real.
 */
final class ServerActionNotAllowed extends RuntimeException
{
    private function __construct(
        public readonly ServerActionRefusal $refusal,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    /**
     * The server is not this customer's, or is not there at all.
     *
     * Deliberately one case. Two would leak the difference.
     */
    public static function noSuchServer(): self
    {
        return new self(ServerActionRefusal::NoSuchServer, 'No such server for this customer.');
    }

    public static function inactiveCustomer(): self
    {
        return new self(ServerActionRefusal::InactiveCustomer, 'That account cannot manage servers.');
    }

    public static function unsupported(ServerActionType $action): self
    {
        return new self(
            ServerActionRefusal::CapabilityUnsupported,
            "This provider does not offer {$action->value}.",
        );
    }

    public static function serverNotLive(): self
    {
        return new self(ServerActionRefusal::ServerNotLive, 'That server is no longer running.');
    }

    public static function noPasswordHeld(): self
    {
        return new self(ServerActionRefusal::NoPasswordHeld, 'No root password is held for that server.');
    }
}
