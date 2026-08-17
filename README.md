# VPS Platform — Phase 1

A production-ready, multi-provider VPS selling and management platform. Customers will
buy and manage servers through a Telegram bot; admins manage the platform through a
Filament panel. **Phase 1** delivers the foundation: Laravel 12 + PostgreSQL + Redis,
the provider/payment abstraction layer, the full database schema, Filament admin
resources, audit logging, and an automated test suite — with **no real provider
integration yet** (FakeProvider and ManualGateway stand in for development and tests).

## Stack

| Layer        | Choice                                             |
|--------------|----------------------------------------------------|
| Backend      | Laravel 12 (PHP 8.3)                               |
| Database     | PostgreSQL 16 (Docker Compose)                     |
| Cache/Queue  | Redis 7 (Docker Compose) — cache, sessions, queues, locks |
| Admin        | Filament 3 + Tailwind CSS                          |
| Web server   | Nginx (Docker Compose)                             |
| Providers    | `CloudProviderInterface` → FakeProvider (Phase 1)  |
| Payments     | `PaymentGatewayInterface` → ManualGateway (Phase 1)|
| Static analysis | Laravel Pint, PHPStan (+ Larastan)             |
| Tests        | Pest                                              |

## Architecture

```
Telegram bot (Phase 4)   ─┐
Filament admin (Phase 1) ─┼──► Services ──► CloudProviderInterface ──► FakeProvider (Phase 1)
                          │                (ProviderManager)          ► HetznerProvider (Phase 2)
                          │                                           ► VultrProvider (Phase 7)
                          └──► PaymentGatewayInterface ──► ManualGateway (Phase 1)
                              (PaymentManager)              ► ZarinpalGateway (Phase 5)
```

- **`app/Contracts/CloudProviderInterface.php`** — the only way the application talks to
  a cloud provider. Methods: `getLocations`, `getPlans`, `getImages`, `createServer`,
  `getServer`, `powerOn`, `powerOff`, `reboot`, `rebuild`, `resetPassword`,
  `deleteServer`, `getUsage` plus a `capabilities()` map so the app never assumes every
  provider supports every action.
- **`app/Providers/Cloud/FakeProvider.php`** — deterministic, zero-network adapter for
  tests and local dev. Supports failure injection (`options: ['fail_create' => true]`).
- **`app/Contracts/PaymentGatewayInterface.php`** — `requestPayment`, `verifyPayment`,
  `refund`; **`app/Providers/Payment/ManualGateway.php`** is the dev/test implementation.
- **`app/Services/`** — `ProviderManager` (resolves adapters with decrypted credentials
  and enabled checks), `PaymentManager`, `PricingService` (markup + FX math),
  `AuditService` (append-only audit log), `OrderService` (order + invoice creation with
  cost/FX snapshot), `PaymentService` (locked, idempotent payment confirmation).
- **`app/Jobs/ProvisionServerJob.php`** — queued provisioning with idempotency guards
  and a Redis lock. Provider failures never mark an order/server as provisioned.
- **`app/Models/`** — 22 models; credentials and root passwords are stored encrypted
  (`encrypted` / `encrypted:array` casts).

## Database

25 migrations. Notable design decisions:

- `provider_plans` / `provider_locations` / `provider_images` are provider-side catalog
  snapshots, fully decoupled from sellable `products`.
- `products` own name, markup strategy (fixed / percentage / custom), selling price,
  billing cycle, enabled status and lifecycle policy.
- `product_prices` keep price history with the FX snapshot used.
- `orders.cost_snapshot` and `servers.*` preserve provider cost, provider currency,
  exchange rate, local cost, selling price and gross margin per sale.
- `provider_credentials` are encrypted at rest; `servers.root_password_encrypted` too.
- `audit_logs` is append-only (`created_at` only).

## Phases

1. ✅ **Phase 1 — Foundation** (this build): Laravel + Postgres + Redis + Docker Compose +
   Filament + FakeProvider + ManualGateway + schema + tests. Stop point.
2. ⏳ **Phase 2** — Real Hetzner adapter + catalog sync + mocked Hetzner tests.
3. ⏳ **Phase 3** — Hetzner sandbox end-to-end provisioning validation.
4. ⏳ **Phase 4** — Telegram bot purchase and management flow.
5. ⏳ **Phase 5** — Billing: ManualGateway + Zarinpal, renewals, expiration, grace period.
6. ⏳ **Phase 6** — Reconciliation jobs, concurrency hardening, Sentry.
7. ⏳ **Phase 7** — Vultr adapter.

## Quickstart

### Docker Compose (Ubuntu target)

```bash
cp .env.example .env        # fill in DB_* and APP_KEY
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
# Admin: http://localhost:8080/admin  (admin@example.com / password)
```

### Local development

```bash
composer install
cp .env.example .env && php artisan key:generate
# create a Postgres database, point .env at it
php artisan migrate --seed
php artisan serve
```

### Tests

```bash
php artisan test                       # SQLite in-memory, sync queue
```

### Static analysis

```bash
vendor/bin/pint                        # code style (Laravel preset)
vendor/bin/phpstan analyse             # level 5, Larastan
```

### End-to-end demo with FakeProvider

```bash
php artisan app:demo-order
```

Runs the full flow — order → invoice → ManualGateway payment → queued provisioning →
server created — and prints the resulting server details.

## Environment variables

`DB_CONNECTION` (pgsql), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD` — PostgreSQL connection. `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`,
`REDIS_CLIENT` (phpredis) — Redis. `CACHE_STORE=redis`, `SESSION_DRIVER=redis`,
`QUEUE_CONNECTION=redis`. `APP_LOCALES=en,fa` — supported locales (Persian RTL + English);
`APP_CURRENCY=IRR`. `APP_KEY` — encryption key for credentials/root passwords.

Phase 2+ variables (documented for when those phases land): `HETZNER_API_TOKEN`
(provider credentials normally live encrypted in `provider_credentials`),
`TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `ZARINPAL_MERCHANT_ID`,
`ZARINPAL_CALLBACK_URL`, `SENTRY_DSN`, `SENTRY_AUTH_TOKEN`.

> Note: provider/payment credentials are never stored in `.env` for runtime use —
> they belong in the encrypted `provider_credentials` table, managed via Filament.

## GitHub deployment (Phase 1 foundation)

The GitHub repository is the single source of truth for the application. The
deployment scripts target a clean Ubuntu 22.04/24.04 server running Docker
Compose, and are safe to run repeatedly.

```bash
git clone https://github.com/ORG/REPO.git /opt/vps-platform
cd /opt/vps-platform
sudo ./install.sh
```

Future convenience installer (once the repository is public):

```bash
curl -fsSL https://raw.githubusercontent.com/ORG/REPO/main/install.sh | sudo bash
```

### Scripts

| Script | Purpose |
|--------|---------|
| `install.sh` | Idempotent installer: root/Ubuntu checks, Git + Docker Engine + Compose plugin bootstrap, Git-aware repository setup, `.env` from `.env.example`, APP_KEY generation, secure config prompts, permissions, build, start all services, migrate, health check, status |
| `update.sh` | Update lock, pre-update backup, `git fetch`, update to `INSTALL_TAG` or `origin/main`, conditional rebuild, safe migrations, restart, health validation, rollback on failure |
| `backup.sh` | PostgreSQL `pg_dump` + persistent `storage/` + configuration (`.env`, compose, nginx) into `backups/` (Git-ignored) |
| `restore.sh` | Restore `.env` + storage + database dump, restart stack, validate health |

### Versioning

Releases are Git tags (`v0.1.0`, `v1.0.0`, …). Servers can pin a release:

```bash
sudo INSTALL_TAG=v1.0.0 ./install.sh     # install a specific release
sudo INSTALL_TAG=v1.2.0 ./update.sh      # update to a specific release
sudo ./update.sh                          # update to origin/main
```

### Repository rules

**Included:** `.env.example`, `.gitignore`, `docker-compose.yml`, `install.sh`,
`update.sh`, `backup.sh`, `restore.sh`.

**Never committed:** `.env` and any `.env.*` local variants, API tokens
(Hetzner/Zarinpal/Telegram), database passwords, private SSH keys, `backups/`,
`.deploy/`.

### Update safety

- PostgreSQL (`pgdata`) and Redis (`redisdata`) named volumes are never deleted.
- `.env` and `storage/` are preserved across reinstalls and updates.
- The update lock (`flock`) prevents concurrent updates.
- A backup is created before every update; rollback restores the previous Git
  revision and revalidates health. Already-applied database migrations are not
  automatically reverted (documented in `update.sh`).

### Health check

`GET /health` returns `200` with `{"status":"ok","services":{"database":"up"}}`
when the database is reachable, `503` otherwise. It exposes no configuration or
secrets. `install.sh` / `update.sh` / `restore.sh` verify it before reporting
success.

## Security notes

- No secrets in code. Credentials and root passwords are encrypted at rest with
  `APP_KEY`; the audit service and logs never receive them.
- Every server action must pass an ownership/authorization check (Phase 4 enforces it
  at the Telegram boundary; the `servers.user_id` ownership is modeled from Phase 1).
- Payment confirmation and provisioning are idempotent and protected by Redis locks.
