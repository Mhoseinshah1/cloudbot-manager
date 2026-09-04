# Decision records

Short records of decisions that were genuinely open — the ones where a
reasonable engineer could have chosen differently and the choice has
consequences worth remembering.

Anything the Master Build Specification already settles does not belong here.
Restating a decided requirement as a "decision" makes it look reopenable.

## Format

One file per decision, `ADR-NNN-short-title.md`, each recording:

- **Context** — the situation that forced a choice
- **Options** — what was genuinely considered
- **Decision** — what was chosen
- **Consequences** — what this makes easy, and what it costs

## Current records

- [ADR-001](ADR-001-fixed-thirty-day-monthly-period.md) — a monthly service
  period is exactly 30 × 24 hours (2,592,000 seconds), not a calendar month.
