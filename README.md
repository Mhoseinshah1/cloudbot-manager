# VPS Platform

A production-ready, multi-provider VPS selling and management platform. Customers
buy and manage servers through a Telegram bot; admins manage the platform through a
Filament panel. The foundation delivers Laravel 12 + PostgreSQL + Redis, the
provider/payment abstraction layer, the full database schema, Filament admin
resources, audit logging, an automated test suite, a production Hetzner Cloud
adapter, and the complete Telegram bot purchase/management flow with billing.
FakeProvider and ManualGateway remain the dev/test stand-ins.

## Stack

| Layer        | Choice                                             |
|--------------|----------------------------------------------------|
| Backend      | Laravel 12 (PHP 8.3)                               |
| Database     | PostgreSQL 16 (Docker Compose)                     |
| Cache/Queue  | Redis 7 (Docker Compose) — cache, sessions, queues, locks |
| Admin        | Filament 3 + Tailwind CSS                          |
| Web server   | Nginx (Docker Compose)                             |
| Providers    | `CloudProviderInterface` → FakeProvider, HetznerProvider (mocked tests) |
| Payments     | `PaymentGatewayInterface` → ManualGateway              |
| Static analysis | Laravel Pint, PHPStan (+ Larastan)             |
| Tests        | Pest                                              |

## Architecture

```
Telegram bot              ─┐
Filament admin ───────────┼──► Services ──► CloudProviderInterface ──► FakeProvider
                          │                (ProviderManager)          ► HetznerProvider
                          │                                           ► VultrProvider (planned)
                          └──► PaymentGatewayInterface ──► ManualGateway
                              (PaymentManager)              ► ZarinpalGateway (planned)
```

- **`app/Contracts/CloudProviderInterface.php`** — the only way the application talks to
  a cloud provider. Methods: `getLocations`, `getPlans`, `getImages`, `getPricing`,
  `createServer`, `getServer`, `powerOn`, `powerOff`, `reboot`, `rebuild`,
  `resetPassword`, `deleteServer`, `getUsage` plus a `capabilities()` map so the app
  never assumes every provider supports every action.
- **`app/Providers/Cloud/FakeProvider.php`** — deterministic, zero-network adapter for
  tests and local dev. Supports failure injection (`options: ['fail_create' => true]`).
- **`app/Providers/Cloud/HetznerProvider.php`** — the production Hetzner Cloud adapter
  (see the Hetzner section below). It talks to the API exclusively through
  `app/Integrations/Hetzner/HetznerApiClient.php` and normalizes every response into the
  shared Data/Value Objects — raw Hetzner structures never leave the adapter.
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

31 migrations. Notable design decisions:

- `provider_plans` / `provider_locations` / `provider_images` are provider-side catalog
  snapshots, fully decoupled from sellable `products`.
- `products` own name, markup strategy (fixed / percentage / custom), selling price,
  billing cycle, enabled status and lifecycle policy.
- `product_prices` keep price history with the FX snapshot used.
- `orders.cost_snapshot` and `servers.*` preserve provider cost, provider currency,
  exchange rate, local cost, selling price and gross margin per sale.
- `provider_credentials` are encrypted at rest; `servers.root_password_encrypted` too.
- `audit_logs` is append-only (`created_at` only).

## Feature Status

1. ✅ **Foundation**: Laravel + Postgres + Redis + Docker Compose + Filament +
   FakeProvider + ManualGateway + schema + tests.
2. ✅ **Hetzner Cloud adapter**: production adapter + API client, catalog sync
   (locations / server types / pricing / images), server actions, idempotent
   provisioning, normalized exceptions, fully mocked test suite.
3. ✅ **Telegram bot**: customer purchase flow (monthly / hourly / hourly_capped),
   server management (power on/off, reboot, rebuild, reset password, delete),
   wallet top-up, billing notifications (Persian), renewal flow.
4. ✅ **Billing core**: hourly, hourly_capped, monthly billing modes; wallet
   service with row-level locking; low-balance warnings; grace period and
   lifecycle actions; PostgreSQL-safe atomic concurrency.
5. ⏳ **Real Hetzner E2E validation**: a dedicated real Hetzner Project with a
   real low-cost VPS (no assumed Hetzner "sandbox" — validation happens against the
   live API on a real project).
6. ⏳ **Payment gateways**: Zarinpal integration (ManualGateway is the current
   dev/test stand-in).
7. ⏳ **Reconciliation jobs, Sentry monitoring**.
8. ⏳ **Vultr adapter**.

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

## Hetzner Cloud integration

### Architecture

```
app/Services (ProviderManager, ProvisionServerJob, ServerActionService, CatalogSyncService)
        │
        ▼
CloudProviderInterface ◄── HetznerProvider (app/Providers/Cloud/HetznerProvider.php)
        │                        │
        │                        ▼
        │              HetznerApiClient (app/Integrations/Hetzner/HetznerApiClient.php)
        │                        │
        │                        ▼
        │              https://api.hetzner.cloud/v1  (Authorization: Bearer <token>)
        └── normalized Data/Value Objects (app/Contracts/Data/*)
```

The application never calls Hetzner (or any provider) directly — everything goes
through `CloudProviderInterface`. `HetznerProvider` normalizes raw API responses into
the shared DTOs and exposes provider errors through normalized application exceptions
(`ProviderAuthenticationException`, `ProviderRateLimitException`,
`ProviderResourceUnavailableException`, `ProviderValidationException`,
`ProviderNotFoundException`, `ProviderConflictException`, `ProviderApiException`).

Key behaviors:

- **Retries**: only safe/transient GET failures (429, 5xx, connection errors) are
  retried with bounded exponential backoff. `POST /servers` and `DELETE` are **never**
  retried blindly — idempotency is enforced by `ProvisionServerJob` (Redis lock,
  `provisioning-uuid` label, `provider_server_id` check, reconciliation-ready
  `findServerByLabel()`).
- **Rate limits**: 429 responses carry `Retry-After` when present.
- **Per-location availability**: server types are synced with their current
  per-location `locations[]`/`prices[]` data (post-Sept-2025 schema) — a plan is only
  offered where the Hetzner catalog says it exists, and per-location deprecations are
  tracked in `provider_plan_prices.deprecated`.
- **No hardcoding**: plans, prices, locations and images come from the live API sync,
  never from static lists.
- **Secrets**: the token is sent only in the `Authorization` header, never logged, and
  never appears in exception messages. Root passwords returned by the API are
  encrypted at rest immediately and delivered once through authorized flows.
- **Metrics**: `getUsage()` maps the official `GET /servers/{id}/metrics?type=cpu`
  response; bandwidth accounting is not invented where Hetzner does not provide it.
- **No generic suspend**: the capabilities map reports `supportsSuspend: false`
  (Hetzner has no provider-neutral suspend action); expiration handling stays in the
  provider-neutral application lifecycle policy.

### Required API token scope

The token is a Hetzner Cloud **API token** created in the Hetzner Cloud Console
(Project → Security → API tokens). Recommended scope:

- **Read** for catalog sync (`GET /locations`, `GET /server_types`, `GET /pricing`,
  `GET /images`, `GET /servers`, `GET /servers/{id}`, `GET /servers/{id}/metrics`)
  and reconciliation.
- **Read & Write** for provisioning and management (`POST /servers`, server actions
  `poweron`/`poweroff`/`reboot`/`rebuild`/`reset_password`, `DELETE /servers/{id}`).

In production the token must be created with the **least privilege** the operation
needs (a read-only token for sync/reconciliation, a read-write token scoped to the
project for provisioning).

### Configuring a Hetzner provider credential

1. **Filament → Providers**: create/select the `hetzner` provider row and mark it
   enabled (the `HetznerProviderSeeder` registers it disabled by default).
2. **Filament → Provider credentials**: add a credential named e.g. `Production`, paste
   the token into the `token` field and set it active. The credential is stored
   encrypted at rest (`encrypted:array` cast) and is never exposed to the UI again.
3. Alternatively, the `HETZNER_API_TOKEN` environment variable is used as a fallback
   when no active credential row exists.
4. **Filament → Providers**: press **Sync Hetzner Catalog** on the provider row (or run
   the command below). The row shows last sync time/status and per-run counts.

### Catalog sync

```bash
php artisan provider:sync hetzner
```

Synchronizes locations, server types (with per-location availability/deprecation),
`/pricing` rows and system images. It is:

- **idempotent** — re-running updates existing rows instead of duplicating them;
- **safe** — admin `enabled` choices on locations/plans are preserved, deprecated and
  unavailable resources are marked (never silently deleted), and local `products` are
  never touched when the provider catalog changes;
- **recorded** — every run writes a `provider_catalog_syncs` row (status, counts,
  errors) shown in the admin panel.

Pagination is handled automatically (`per_page` + `meta.pagination.next_page`);
multi-page responses are covered by tests.

### Mocked testing

All Hetzner tests use `Http::fake()` against `Tests\Fixtures\HetznerApiFixtures` —
realistic payloads matching the current official API schemas. No network access and
no real token are required; run the suite with `php artisan test`. Coverage includes
auth headers, multi-page pagination, per-location availability, pricing, images,
server creation (success + failure), retrieval, power/lifecycle actions, password
reset, delete, rate limits, 401/404/409/422/5xx, timeouts, duplicate-provisioning
protection, response normalization and secret redaction.

## Billing core (hourly / hourly_capped)

Products declare an explicit `billing_mode` — `monthly`, `hourly` or `hourly_capped`
(`App\Enums\BillingMode`) — which is never inferred from provider catalog pricing.
Customer prices (hourly rate, monthly cap) are platform-controlled integer toman
values stored alongside the provider cost snapshot, so provider cost and margin are
never exposed to customers.

- **`App\Services\WalletService`** is the single authority over wallet balances:
  every credit/debit runs in a transaction with a row lock and writes an auditable
  `wallet_transactions` row carrying the post-mutation balance. No other code mutates
  `wallets.balance_toman` directly.
- **`App\Services\HourlyBillingService`** is the hourly charge engine. Billing starts
  when a server is successfully provisioned (`billing_started_at`) and stops only on
  permanent deletion (`billing_stopped_at`); `power_on`/`power_off`/`reboot` are
  server actions and never start or stop billing. Charges settle from the customer
  wallet only, in one-hour units on the server's unit grid, with a configured rounding
  policy for partial hours (`billing.hourly_rounding`, default `ceil`).
- **Idempotency & safety**: each server is processed under a per-server lock and a DB
  transaction; `server_billing_periods` carries a unique `(server_id, period_start,
  period_end)` index so an interval can never be charged twice. Unpaid intervals are
  recorded as `unpaid` ledger rows instead of mutating the wallet.
- **`hourly_capped`** stops charging once paid usage in the current service period
  reaches the product's monthly customer cap. The cap period is anchored to the
  server's billing cycle, NOT calendar months. For example, a server started on
  Aug 31 15:00 has its first cap period end on Sep 30 15:00.
- **Scheduling**: `php artisan billing:process-hourly` dispatches
  `ProcessHourlyBillingJob` (`--sync` processes inline); the scheduler runs it hourly
  with `withoutOverlapping`.

Seed data (`FakeProviderSeeder`) ships three demo products: `vps-cx21` (monthly,
399,000 toman), `vps-cx21-hourly` (850 toman/hour) and `vps-cx21-capped`
(850 toman/hour, 399,000 toman/month cap).

## Environment variables

`DB_CONNECTION` (pgsql), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD` — PostgreSQL connection. `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`,
`REDIS_CLIENT` (phpredis) — Redis. `CACHE_STORE=redis`, `SESSION_DRIVER=redis`,
`QUEUE_CONNECTION=redis`. `APP_LOCALES=en,fa` — supported locales (Persian RTL + English);
`APP_CURRENCY=IRR`. `APP_KEY` — encryption key for credentials/root passwords.

Production variables: `HETZNER_API_TOKEN` (fallback only — provider credentials
normally live encrypted in `provider_credentials`), `TELEGRAM_BOT_TOKEN`,
`TELEGRAM_WEBHOOK_SECRET`, `ZARINPAL_MERCHANT_ID`, `ZARINPAL_CALLBACK_URL`,
`SENTRY_DSN`, `SENTRY_AUTH_TOKEN`.

> Note: provider/payment credentials are never stored in `.env` for runtime use —
> they belong in the encrypted `provider_credentials` table, managed via Filament.

## Ubuntu / Docker Deployment

The GitHub repository (`main` branch) is the single source of truth. The
deployment scripts target Ubuntu 22.04 or 24.04 running Docker Compose, and are
safe to run repeatedly.

### Standard install (clone)

```bash
git clone https://github.com/Mhoseinshah1/cloudbot-manager.git /opt/cloudbot-manager
cd /opt/cloudbot-manager
sudo ./install.sh
```

SSH alternative:

```bash
git clone git@github.com:Mhoseinshah1/cloudbot-manager.git /opt/cloudbot-manager
cd /opt/cloudbot-manager
sudo ./install.sh
```

### One-line public installer

```bash
curl -fsSL https://raw.githubusercontent.com/Mhoseinshah1/cloudbot-manager/main/install.sh | sudo bash
```

### Configurable variables

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_DIR` | `/opt/cloudbot-manager` | Target installation directory |
| `APP_PORT` | `8080` | Host port for Nginx — choose a free port |
| `DB_PASSWORD` | prompted | PostgreSQL password |
| `INSTALL_TAG` | (empty, uses `main`) | Git tag/release to pin |
| `SEED` | `0` | Set to `1` to seed demo data (admin@example.com) |

Example with a custom port:

```bash
sudo APP_PORT=8085 ./install.sh
```

> `8085` is only an example — choose a port that is not in use on your server.

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
- Every server action passes an ownership/authorization check enforced at the
  Telegram boundary; `servers.user_id` is the authoritative ownership column.
- Payment confirmation and provisioning are idempotent and protected by Redis locks.
