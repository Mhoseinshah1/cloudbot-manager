# CloudBot Manager

A platform for selling and managing VPS / cloud servers. Customers buy and
manage servers through a Telegram bot; staff operate the system through a web
admin panel.

**Status: Phase 1 (Foundation) only.** This repository currently contains the
application skeleton and its infrastructure. None of the product features exist
yet — see [What is not built yet](#what-is-not-built-yet).

## What Phase 1 provides

- Laravel 12 on PHP 8.3, timestamps in UTC
- PostgreSQL 16 as the only database, with real PostgreSQL integration tests
- Redis 7 with one logical database per concern (cache, queues, bot state, locks)
- A `/health` endpoint and a matching `app:health` command
- Structured JSON logging to stderr, with credentials redacted
- A Docker Compose topology: app, nginx, postgres, redis, three queue workers, scheduler
- GitHub Actions CI covering tests, static analysis, security scanning, shell and Docker

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

## What is not built yet

Nothing below exists in the repository yet. Each arrives in its own phase:

customers and Telegram identity · admin roles and the Filament panel · the
provider abstraction, FakeProvider and Hetzner · wallet, payments and invoices ·
products, pricing and exchange rates · orders and refunds · provisioning and
reconciliation · the Telegram bot itself · subscriptions, renewals and expiry ·
install, update, backup and restore scripts.

Release 1.0 bills monthly only. Hourly and capped billing are Release 1.1.

## Documentation

Architecture and business decisions that the specification does not already
settle are recorded in [`docs/decisions/`](docs/decisions/).
