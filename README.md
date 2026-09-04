# CloudBot Manager

A platform for selling and managing VPS / cloud servers. Customers buy and
manage servers through a Telegram bot; staff operate the system through a web
admin panel.

**Status: foundation and identity only.** The infrastructure and the staff
identity model exist; none of the product features do — see
[What is not built yet](#what-is-not-built-yet).

## What exists so far

- Laravel 12 on PHP 8.3, timestamps in UTC
- PostgreSQL 16 as the only database, with real PostgreSQL integration tests
- Redis 7 with one logical database per concern (cache, queues, bot state, locks)
- A `/health` endpoint and a matching `app:health` command
- Structured JSON logging to stderr, with credentials redacted
- A Docker Compose topology: app, nginx, postgres, redis, three queue workers, scheduler
- GitHub Actions CI covering tests, static analysis, security scanning, shell and Docker
- Customer and administrator identity, Telegram account records, database-backed
  settings and an append-only audit trail
- An admin panel behind role-based access and mandatory two-factor authentication
- A provider abstraction with a zero-network FakeProvider and a conformance suite

## Requirements

- PHP 8.3+ with `bcmath`, `intl`, `pcntl`, `pdo_pgsql`, `pgsql`, `redis`, `zip`
- Composer 2
- PostgreSQL 16 and Redis 7 (directly, or via Docker)
- Docker and Docker Compose, to run the containerised stack

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate          # once; never regenerate on an existing install
```

Point `DB_*` and `REDIS_*` at your local services, create the databases, then:

```bash
php artisan migrate
# Publishes the admin panel's CSS and JS. Build output, so it is not committed;
# the Docker image generates it during the build.
php artisan filament:assets
php artisan serve
```

## Tests

The suite runs against a real PostgreSQL and Redis. SQLite is deliberately not
supported: this system's correctness depends on PostgreSQL locking semantics and
on Redis database separation, neither of which SQLite can demonstrate.

Create the test database once (`cloudbot_test` by default), then:

```bash
vendor/bin/pest              # full suite
vendor/bin/pest --group=...  # or any Pest filter
vendor/bin/phpstan analyse   # static analysis
vendor/bin/pint --test       # code style
composer audit               # known vulnerabilities in dependencies
```

## Docker

```bash
cp .env.example .env
php artisan key:generate                     # APP_KEY must be set before starting
docker compose up -d --build

# Schema changes are an explicit step, never run automatically on container start:
docker compose run --rm app php artisan migrate --force
```

For local development, which also publishes PostgreSQL and Redis on loopback:

```bash
docker compose -f compose.yaml -f compose.dev.yaml up -d
```

Only nginx publishes a port, and it binds to `127.0.0.1:8080` by default.
PostgreSQL and Redis are reachable only on the internal network. Public TLS is
expected to terminate at the host's own nginx in front of this stack.

## Health

```bash
curl http://127.0.0.1:8080/health
```

```json
{"status":"ok","services":{"database":"up","redis":"up"}}
```

Returns `503` with `"status":"degraded"` when a dependency is unreachable. The
response carries service states only — no versions, hostnames or error detail.
Containers without HTTP use the same check via `php artisan app:health`.

## Administrators

Staff use the admin panel at `/admin`. There is no customer web interface:
customers use the Telegram bot.

Create the first account:

```bash
php artisan app:create-admin
```

It prompts for a name, an email address and a password (hidden, entered twice),
creates an active account with the `owner` role, and refuses to touch an account
that already exists — so re-running an installer can never reset a password or
grant a role by accident.

### Roles

Three roles, provisioned idempotently by the `RolePermissionSeeder`:

| Role | Holds |
|---|---|
| `owner` | Every permission |
| `finance` | Payments, refunds, wallet adjustments, invoices, financial reporting |
| `support` | Customers, orders, servers, audit viewing — and no financial permission |

Being privileged means holding one of these roles. There is no `is_admin` flag.

```bash
php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder
```

### Two-factor authentication

Signing in takes two steps. A correct password establishes who someone claims
to be; it grants no privileged access on its own. The session then has to pass a
challenge — a current TOTP code, or one unused recovery code — before any other
page in the panel will load.

An administrator who has not enrolled can reach only the enrolment page. One who
has enrolled is sent to the challenge and is deliberately *not* allowed back
into enrolment, since that would let a stolen password register a new device.

The proof lives in the session and is bound to the account that earned it, so it
does not carry over to another user and does not survive logout. There is no
database flag recording that an account has "passed 2FA": such a flag would
outlive the session and make a stolen password sufficient again. A new sign-in
means a new challenge.

Repeated failures are rate limited per account — a delay, not a lockout. Secrets
and recovery codes are encrypted at rest, single-use in the case of recovery
codes, and never written to the session, logs or the audit trail.

The requirement can be relaxed outside production for automated tests. In
production it always applies, regardless of configuration.

## Cloud providers

Providers sit behind one small interface. Anything not needed to sell, deliver
and remove a server is an optional capability interface instead, so a provider
that lacks it simply does not implement it rather than implementing it to throw.
What a provider can do is answered by asking the adapter, never by a list
maintained alongside it.

Nothing provider-native crosses the boundary: adapters return normalized objects,
and failures are thrown with a normalized category so business code never has to
read an HTTP status or a message to decide whether to retry, refund or reconcile.

**Implementations are named only in `config/providers.php`.** The `code` on a
provider row selects an entry there. A class name is never stored in the
database, because a write to that table would otherwise decide what code this
application instantiates.

### FakeProvider

`FakeProvider` implements the full contract and never touches the network. It is
for the test suite, local development and staging demonstrations — it is not a
stub: creating, listing, powering, rebooting and deleting all behave as the
contract requires, including returning the same server when a create is retried
with the same provisioning token.

Its simulated remote state lives in PostgreSQL, in tables named
`fake_provider_*`. That is deliberate. State in a static array would not survive
a queue worker or a restart, and only a database can enforce the unique
constraint on the provisioning token that makes create idempotency true under
concurrency rather than merely likely.

Those tables stand in for another company's infrastructure. They are not the
local record of a server a customer bought, which arrives in a later phase.

### Conformance suite

`Tests\Support\Cloud\ProviderConformance` defines the behaviour every adapter
must exhibit, written against the interface alone. FakeProvider runs through it
today; the Hetzner adapter will run through the same suite unchanged rather than
getting a copy that can drift.

**There is no Hetzner implementation yet**, and no registry entry for one: an
entry pointing at a provider that cannot provision would be a promise the system
could not keep.

## Operational notes

- **`APP_KEY` is generated once and preserved.** It encrypts stored credentials;
  replacing it makes existing encrypted values unreadable. Containers refuse to
  start with an empty key rather than generating one.
- **`cache:clear` is safe.** It flushes only the cache database. Queued jobs, bot
  conversation state and locks live in separate Redis databases, and tests prove
  a cache flush leaves them intact.
- **Migrations are never automatic.** They run as an explicit deployment step
  with workers stopped, so two starting containers cannot race the migrator.
- **No default database password.** Compose refuses to start unless
  `DB_PASSWORD` is set.
- **The audit trail cannot be rewritten.** The model refuses updates and
  deletes, and PostgreSQL triggers reject them too, so an entry survives even
  code that never loads the model.

## What is not built yet

Nothing below exists in the repository yet. Each arrives in its own phase:

the Hetzner adapter · wallet, payments and invoices · products, pricing and exchange rates · orders and refunds ·
provisioning and reconciliation · the Telegram bot itself, including webhook
handling and the buy flow · subscriptions, renewals and expiry · notifications ·
the operational admin screens (the panel currently has sign-in and two-factor
enrolment only) · install, update, backup and restore scripts.

Release 1.0 bills monthly only. Hourly and capped billing are Release 1.1.

## Documentation

Architecture and business decisions that the specification does not already
settle are recorded in [`docs/decisions/`](docs/decisions/).
