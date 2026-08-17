# VPS Platform

A production-ready, multi-provider VPS selling and management platform. Customers will
buy and manage servers through a Telegram bot; admins manage the platform through a
Filament panel. **Phase 1** delivered the foundation: Laravel 12 + PostgreSQL + Redis,
the provider/payment abstraction layer, the full database schema, Filament admin
resources, audit logging, and an automated test suite. **Phase 2** adds the real
Hetzner Cloud adapter (production code, fully mocked tests — no live API calls yet).
FakeProvider and ManualGateway remain the dev/test stand-ins.

## Stack

| Layer        | Choice                                             |
|--------------|----------------------------------------------------|
| Backend      | Laravel 12 (PHP 8.3)                               |
| Database     | PostgreSQL 16 (Docker Compose)                     |
| Cache/Queue  | Redis 7 (Docker Compose) — cache, sessions, queues, locks |
| Admin        | Filament 3 + Tailwind CSS                          |
| Web server   | Nginx (Docker Compose)                             |
| Providers    | `CloudProviderInterface` → FakeProvider, HetznerProvider (mocked) |
| Payments     | `PaymentGatewayInterface` → ManualGateway (Phase 1)|
| Static analysis | Laravel Pint, PHPStan (+ Larastan)             |
| Tests        | Pest                                              |

## Architecture

```
Telegram bot (Phase 4)   ─┐
Filament admin ───────────┼──► Services ──► CloudProviderInterface ──► FakeProvider
                          │                (ProviderManager)          ► HetznerProvider (Phase 2)
                          │                                           ► VultrProvider (Phase 7)
                          └──► PaymentGatewayInterface ──► ManualGateway
                              (PaymentManager)              ► ZarinpalGateway (Phase 5)
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

## Phases

1. ✅ **Phase 1 — Foundation**: Laravel + Postgres + Redis + Docker Compose + Filament +
   FakeProvider + ManualGateway + schema + tests.
2. ✅ **Phase 2 — Hetzner Cloud adapter** (this build): production adapter + API client,
   catalog sync (locations / server types / pricing / images), server actions,
   idempotent provisioning, normalized exceptions, fully mocked test suite.
3. ⏳ **Phase 3 — Real Hetzner E2E validation**: a dedicated real Hetzner Project with a
   real low-cost VPS (no assumed Hetzner "sandbox" — validation happens against the
   live API on a real project).
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

## Hetzner Cloud integration (Phase 2)

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

## Environment variables

`DB_CONNECTION` (pgsql), `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD` — PostgreSQL connection. `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`,
`REDIS_CLIENT` (phpredis) — Redis. `CACHE_STORE=redis`, `SESSION_DRIVER=redis`,
`QUEUE_CONNECTION=redis`. `APP_LOCALES=en,fa` — supported locales (Persian RTL + English);
`APP_CURRENCY=IRR`. `APP_KEY` — encryption key for credentials/root passwords.

Phase 2+ variables: `HETZNER_API_TOKEN` (fallback only — provider credentials
normally live encrypted in `provider_credentials`), `TELEGRAM_BOT_TOKEN`,
`TELEGRAM_WEBHOOK_SECRET`, `ZARINPAL_MERCHANT_ID`, `ZARINPAL_CALLBACK_URL`,
`SENTRY_DSN`, `SENTRY_AUTH_TOKEN`.

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
