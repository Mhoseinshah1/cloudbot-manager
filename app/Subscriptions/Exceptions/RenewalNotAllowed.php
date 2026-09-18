<?php

declare(strict_types=1);

namespace App\Subscriptions\Exceptions;

use RuntimeException;

/**
 * A renewal that must not proceed, and the reason a customer can be told.
 *
 * The reason is a stable code rather than prose, because the Telegram layer
 * turns it into a message and an operator turns it into a support answer;
 * neither should depend on wording.
 */
final class RenewalNotAllowed extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notRenewable(string $status): self
    {
        return new self('not_renewable', 'This subscription cannot be renewed while it is '.$status.'.');
    }

    public static function notMonthly(): self
    {
        return new self('not_monthly', 'Only monthly subscriptions can be renewed.');
    }

    public static function serverGone(): self
    {
        return new self('server_gone', 'The server for this subscription no longer exists.');
    }

    public static function notYours(): self
    {
        return new self('not_yours', 'That subscription does not belong to this customer.');
    }

    public static function inactiveCustomer(): self
    {
        return new self('inactive_customer', 'This account may not renew services.');
    }

    public static function insufficientFunds(int $required, int $available): self
    {
        return new self(
            'insufficient_funds',
            'The wallet holds '.$available.' Toman and this renewal costs '.$required.'.',
        );
    }

    public static function priceUnavailable(string $detail): self
    {
        return new self('price_unavailable', 'This renewal cannot be priced right now: '.$detail);
    }

    /**
     * Deletion has already been requested for the expired period.
     *
     * Past this point the machine may be being destroyed at the provider, and
     * taking money for it would be charging for something that is going away.
     */
    public static function terminationInProgress(): self
    {
        return new self('termination_in_progress', 'This server is already being terminated.');
    }

    public static function stalePrice(int $newPrice): self
    {
        return new self('price_changed', 'The renewal price has changed to '.$newPrice.' Toman.');
    }
}
