# ADR-002 — What counts against a customer's active-server limit

## Context

The Master Build Specification requires a configurable maximum number of active
servers per customer (§39) and suggests 3 for a new one, but it does not say
what "active" counts. Phase 9 has to answer that, because it is the phase where
a customer can buy a server without anybody looking.

The obvious reading — count the servers they currently hold — has a hole that is
easy to find and expensive to leave open. A server does not exist until a
provider has built it, and building takes minutes. A customer at the ceiling of
three could place a fourth order, pay for it and have it delivered while the
count still said three, because none of the in-flight work is a server yet.

Ordering is not even required. Nothing stops a customer creating several unpaid
orders while holding nothing at all, each one passing a check that sees no
commitment, and then paying all of them. The purchase velocity limit slows that
down and does not close it: a patient customer waits out the window.

The specification's suggested limit of 3 is small enough that the difference
matters. A limit that can be exceeded by a factor of four by clicking quickly is
not a limit.

## Options

1. **Count delivered servers only.** Simplest, and matches the words. Leaves the
   hole above: the ceiling is advisory for anybody willing to click.
2. **Count delivered servers plus funded orders.** Closes the fast case — a
   customer cannot pay for more than the ceiling allows — but still lets orders
   be stockpiled unpaid and settled later.
3. **Count delivered servers plus every order that could still become one.**
   Closes both. Costs a customer a slot while an order of theirs is unpaid or
   stuck, which they can release by cancelling it.

## Decision

A customer's used capacity is:

- every `Server` of theirs whose status is not `terminated` — including
  `suspended`, `missing` and `needs_attention`, because each is still a
  commitment somebody has to resolve; plus
- every `Order` of theirs in `pending`, `awaiting_payment`, `paid`,
  `provisioning` or `needs_attention` that has not yet produced a `Server`.

An order that already has its server is not counted twice: the server is in the
first count. Orders in `provisioned`, `failed`, `refunded`, `expired` and
`cancelled` hold no slot.

The count is taken with the customer's row locked, inside the transaction that
creates the order, so two purchases racing for the last slot queue behind the
lock rather than both seeing room.

Both the ceiling and the velocity limit are compulsory: absent or unreadable
configuration refuses new purchases. Existing servers stay viewable and
manageable, so a misconfiguration stops sales rather than stranding customers.

## Consequences

**Makes easy.** The ceiling holds under real concurrency, and against a customer
who stockpiles orders and pays them later. The rule is one query and reads the
same way in a support conversation: everything you have, plus everything you
have asked for.

**Costs.** A customer with an order stuck at `needs_attention` occupies a slot
until an operator resolves it, which is the conservative direction but is still
a customer waiting. One sitting on a stale unpaid order occupies a slot until it
expires or they cancel it; the flow offers cancellation, and cancelling frees it
immediately.

**Not decided here.** Whether the limit should vary by customer age or standing.
Release 1.0 has one number for everybody, as the specification describes.
