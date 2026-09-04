<?php

declare(strict_types=1);

namespace App\Telegram\Flows;

use App\Models\User;
use App\Telegram\Data\CallbackParameters;

/**
 * Who is talking, where to answer, and what their button carried.
 *
 * Passed rather than rediscovered. The Telegram identity and the customer are
 * two different things and both are needed: conversation state is keyed by the
 * numeric Telegram id, because that is what survives a username changing hands,
 * while everything a customer owns hangs off the User row.
 *
 * The parameters are hints from a pressed button. Never authority — every
 * lookup that uses one is scoped by customer in the query.
 */
final readonly class FlowContext
{
    public function __construct(
        public User $customer,
        public int $chatId,
        public int $telegramUserId,
        public CallbackParameters $parameters,
    ) {}

    public function id(): ?int
    {
        return $this->parameters->id;
    }

    public function page(): int
    {
        return $this->parameters->page ?? 1;
    }

    public function flowToken(): ?string
    {
        return $this->parameters->flowToken;
    }
}
