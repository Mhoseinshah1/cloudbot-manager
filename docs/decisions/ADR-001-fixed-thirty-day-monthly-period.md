# ADR-001 — A monthly service period is exactly 30 × 24 hours

## Context

The Master Build Specification fixes `subscriptions.current_period_end` as the
single authoritative expiry (§6.20, §17) but never says how long a "monthly"
period is. Phase 7 has to write that value the moment a server is delivered, so
the question could not stay open any longer.

Two readings were genuinely available, and they disagree by up to three days:

- A **calendar** month — 4 February plus one month is 4 March, and 31 January
  plus one month is either 28 February or 3 March depending on the overflow rule
  chosen. Period length varies between 28 and 31 days.
- A **fixed elapsed duration** — every period is the same number of seconds,
  whenever it starts.

The difference is customer-visible money. Under a calendar month a customer who
buys in February pays the same price for 28 days that a customer buying in March
pays for 31, and the renewal date drifts as periods compound.

## Options

1. **Calendar month** (`addMonth()`): matches how people say "monthly", but
   makes the price-per-day depend on the month, and forces an arbitrary rule for
   the 29th, 30th and 31st that no requirement states.
2. **Anniversary date with clamping**: same drift, plus a special case that
   silently moves a customer's renewal day earlier and never moves it back.
3. **Fixed 30 × 24 hours**: identical value for every customer in every month,
   no overflow rule to invent, and expiry is a subtraction rather than a
   calendar library's judgement.

## Decision

A monthly service period is exactly:

```text
30 * 24 hours  =  720 hours  =  2,592,000 seconds
```

For the initial subscription written at successful provisioning:

```text
current_period_start = the provisioning success instant (UTC)
current_period_end   = current_period_start + 2,592,000 seconds
```

Stored and calculated in UTC. The same immutable instant is written to
`orders.provisioned_at` and `subscriptions.current_period_start`, so the two can
never disagree about when service began.

This is elapsed-duration arithmetic, not calendar arithmetic. `addMonth()`,
`endOfMonth()`, Jalali month boundaries and calendar-overflow rules are
excluded: a period that lands on the same wall-clock date is a coincidence of
30-day months, not the rule.

Phase 11 inherits the same fixed 30-day length for monthly renewal periods
unless that is explicitly changed.

## Consequences

Easy:

- Period length is a constant, so expiry is exact and testable to the second.
- No overflow rule, so no month has a special case anyone has to remember.
- Every customer receives the same service for the same money.
- Renewal in Phase 11 is one addition against the authoritative period end.

Costs:

- Renewal dates drift against the calendar: twelve periods are 360 days, so a
  subscription bought on 1 January renews on 27 December the following year.
  That is a real customer-facing consequence and it is accepted deliberately,
  because the alternative is charging different amounts for different months.
- "Monthly" in customer-facing copy means 30 days and should be written that way
  wherever a precise figure is quoted.
