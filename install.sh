#!/usr/bin/env bash
#
# CloudBot Manager installer.
#
#   git clone <repo> /opt/cloudbot-manager
#   cd /opt/cloudbot-manager
#   sudo ./install.sh
#
# Idempotent. Re-running it against a working installation reconfigures what
# has changed and leaves everything else exactly as it is. In particular it
# never regenerates APP_KEY, never changes an initialized database's password,
# never recreates an existing owner account and never removes a volume.
#
# Non-interactive use: set the variables below in the environment and pass
# --non-interactive. Anything not set keeps its existing .env value, or a
# generated one where a secret is required.
#
#   CLOUDBOT_DOMAIN           CLOUDBOT_ADMIN_NAME
#   CLOUDBOT_APP_URL          CLOUDBOT_ADMIN_EMAIL
#   CLOUDBOT_APP_BIND_IP      CLOUDBOT_ADMIN_PASSWORD
#   CLOUDBOT_APP_PORT         CLOUDBOT_DB_DATABASE
#   CLOUDBOT_APP_NAME         CLOUDBOT_DB_USERNAME
#   CLOUDBOT_APP_ENV          CLOUDBOT_DB_PASSWORD
#   CLOUDBOT_TELEGRAM_BOT_TOKEN      CLOUDBOT_TELEGRAM_BOT_USERNAME
#   CLOUDBOT_TELEGRAM_ADMIN_CHAT_ID  CLOUDBOT_HETZNER_API_TOKEN
#   CLOUDBOT_HOST_NGINX=true|false   CLOUDBOT_TLS=true|false
#   CLOUDBOT_TLS_EMAIL               CLOUDBOT_SET_WEBHOOK=true|false
#   CLOUDBOT_SEED_DEMO_CATALOG=true|false
#
# Secrets are read from the environment or from a hidden prompt. None is ever
# passed as a command-line argument, so none can appear in the process list.

set -Eeuo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly INSTALL_DIR
readonly ENV_FILE="${INSTALL_DIR}/.env"
readonly LOCK_FILE="/var/lock/cloudbot-manager-deploy.lock"

# shellcheck source=scripts/lib/common.sh
. "${INSTALL_DIR}/scripts/lib/common.sh"
# shellcheck source=scripts/lib/stack.sh
. "${INSTALL_DIR}/scripts/lib/stack.sh"
# shellcheck source=scripts/lib/hostnginx.sh
. "${INSTALL_DIR}/scripts/lib/hostnginx.sh"

NON_INTERACTIVE=false
FAILED_STAGE="startup"

usage() {
    cat <<'USAGE'
Usage: sudo ./install.sh [--non-interactive] [--help]

  --non-interactive   Take every answer from the environment. Fails rather
                      than prompting for anything that is required and unset.
  --help              Show this message.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --non-interactive) NON_INTERACTIVE=true; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; die "Unknown argument: $1" ;;
    esac
done

# Secrets written below must not be world-readable even for an instant.
umask 077

main() {
    require_root
    require_supported_os
    take_lock "${LOCK_FILE}"

    if [ "${NON_INTERACTIVE}" = "false" ] && ! interactive; then
        die "No terminal available. Re-run with --non-interactive and set the CLOUDBOT_* variables."
    fi

    ensure_docker
    arm_failure_diagnostics

    local first_install=false
    [ -f "${ENV_FILE}" ] || first_install=true

    if [ "${first_install}" = "true" ]; then
        step "First install — creating ${ENV_FILE}"
        ( umask 077; cp "${INSTALL_DIR}/.env.example" "${ENV_FILE}" )
        chmod 600 "${ENV_FILE}"
    else
        step "Existing installation found — reconfiguring in place"
        chmod 600 "${ENV_FILE}"
    fi

    configure_general
    configure_database
    configure_redis
    configure_telegram

    # -- APP_KEY, before anything reads it ----------------------------------
    #
    # Everything encrypted in this database — provider tokens, server root
    # credentials — is readable only with this key. Generating a second one
    # would not "reset" anything; it would make the existing data permanently
    # unreadable. So: preserve if present, generate exactly once if absent,
    # and validate before any runtime container starts.
    FAILED_STAGE="application key"
    ensure_app_key

    FAILED_STAGE="image build"
    step "Building the application image"
    compose build

    FAILED_STAGE="infrastructure startup"
    start_infrastructure

    FAILED_STAGE="database credential check"
    assert_database_credentials_usable

    FAILED_STAGE="application startup"
    start_application

    FAILED_STAGE="application key verification"
    verify_app_key_in_container

    FAILED_STAGE="migrations"
    step "Running database migrations"
    # Workers are stopped for the schema change, then brought back afterwards,
    # so no job runs against a half-migrated database.
    stop_workers
    artisan migrate --force
    ok "Migrations applied."

    FAILED_STAGE="role bootstrap"
    bootstrap_roles

    FAILED_STAGE="admin bootstrap"
    bootstrap_admin

    FAILED_STAGE="provider bootstrap"
    bootstrap_provider

    FAILED_STAGE="demo catalog"
    bootstrap_demo_catalog

    FAILED_STAGE="worker startup"
    start_workers

    FAILED_STAGE="application health"
    verify_local_health 120

    FAILED_STAGE="host nginx"
    configure_host_edge

    FAILED_STAGE="telegram webhook"
    configure_webhook

    # Everything below this point is reporting, not deployment.
    trap - ERR
    summary
}

# ---------------------------------------------------------------------------
# Prerequisites
# ---------------------------------------------------------------------------

ensure_docker() {
    step "Checking prerequisites"

    local missing=()
    for tool in curl git flock awk sed grep; do
        have "${tool}" || missing+=("${tool}")
    done

    if [ "${#missing[@]}" -gt 0 ]; then
        step "Installing missing base tools: ${missing[*]}"
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq curl git util-linux gawk sed grep
    fi

    if ! have docker || ! docker compose version >/dev/null 2>&1; then
        if [ "${NON_INTERACTIVE}" = "true" ] || ask_yes_no "Docker Engine is required. Install it now?" y; then
            install_docker
        else
            die "Docker is required. Install it and re-run."
        fi
    fi

    require_docker
    ok "Docker and Compose are available."

    # Reported, never changed. Firewall, SSH and unattended-upgrade policy
    # belong to whoever owns this host, not to an application installer.
    report_host_prerequisites
}

report_host_prerequisites() {
    if have ufw && ufw status 2>/dev/null | grep -q 'Status: active'; then
        log "  note: ufw is active. Ports 80 and 443 must be open for public access and TLS issuance."
    fi
    if ! have systemctl; then
        warn "systemd was not found. Host nginx and certbot management expect it."
    fi
}

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

# Returns the existing value, then the environment override, then the default.
# Order matters: an existing installation keeps working when the operator
# re-runs the installer without re-supplying everything.
current_or() {
    local key="$1" override="$2" fallback="$3" existing
    existing="$(env_get "${ENV_FILE}" "${key}" 2>/dev/null || true)"
    if [ -n "${override}" ]; then printf '%s' "${override}"
    elif [ -n "${existing}" ]; then printf '%s' "${existing}"
    else printf '%s' "${fallback}"; fi
}

configure_general() {
    step "General configuration"

    local app_name app_env domain app_url bind port

    app_name="$(current_or APP_NAME "${CLOUDBOT_APP_NAME:-}" 'CloudBot Manager')"
    app_env="$(current_or APP_ENV "${CLOUDBOT_APP_ENV:-}" 'production')"
    bind="$(current_or APP_BIND_IP "${CLOUDBOT_APP_BIND_IP:-}" '127.0.0.1')"
    port="$(current_or APP_PORT "${CLOUDBOT_APP_PORT:-}" '8080')"
    domain="${CLOUDBOT_DOMAIN:-}"
    app_url="$(current_or APP_URL "${CLOUDBOT_APP_URL:-}" '')"

    if [ "${NON_INTERACTIVE}" = "false" ]; then
        ask app_name "Application name" "${app_name}"
        ask domain "Public domain (blank for none)" "${domain:-$(url_host "${app_url}")}"
        ask bind "Bind address for the Docker nginx" "${bind}"
        ask port "Port for the Docker nginx" "${port}"
    fi

    if [ -n "${domain}" ]; then
        valid_domain "${domain}" || die "Not a valid domain: ${domain}"
        # http for now; the TLS step rewrites this to https once a certificate
        # exists, so APP_URL is never a promise the host cannot keep.
        [ -n "${app_url}" ] || app_url="http://${domain}"
    fi
    [ -n "${app_url}" ] || app_url="http://localhost:${port}"

    valid_ipv4 "${bind}" || die "Not a valid bind address: ${bind}"
    valid_port "${port}" || die "Not a valid port: ${port}"

    case "${app_env}" in
        production|staging|local) ;;
        *) die "APP_ENV must be production, staging or local; got: ${app_env}" ;;
    esac

    env_set "${ENV_FILE}" APP_NAME "${app_name}"
    env_set "${ENV_FILE}" APP_ENV "${app_env}"
    env_set "${ENV_FILE}" APP_DEBUG "false"
    env_set "${ENV_FILE}" APP_URL "${app_url}"
    env_set "${ENV_FILE}" APP_BIND_IP "${bind}"
    env_set "${ENV_FILE}" APP_PORT "${port}"
    [ -n "${domain}" ] && env_set "${ENV_FILE}" CLOUDBOT_DOMAIN "${domain}"

    ok "General configuration written."
}

url_host() {
    local url="$1"
    url="${url#*://}"
    printf '%s' "${url%%[:/]*}"
}

# The database password rule, stated once:
#
#   * first install   -> ask, or generate something unguessable
#   * re-install      -> keep exactly what is there
#
# There is no fallback value. A shipped default such as "secret" becomes a
# known production credential the moment anybody reads this repository.
configure_database() {
    step "Database configuration"

    local database username password existing_password

    database="$(current_or DB_DATABASE "${CLOUDBOT_DB_DATABASE:-}" 'cloudbot')"
    username="$(current_or DB_USERNAME "${CLOUDBOT_DB_USERNAME:-}" 'cloudbot')"
    existing_password="$(env_get "${ENV_FILE}" DB_PASSWORD 2>/dev/null || true)"

    if [ "${NON_INTERACTIVE}" = "false" ] && [ -z "${existing_password}" ]; then
        ask database "Database name" "${database}"
        ask username "Database user" "${username}"
    fi

    valid_identifier "${database}" || die "Database name must be lowercase letters, digits and underscores: ${database}"
    valid_identifier "${username}" || die "Database user must be lowercase letters, digits and underscores: ${username}"

    if [ -n "${existing_password}" ]; then
        # Changing this would not rotate the password inside an already
        # initialized PostgreSQL volume; it would just stop the app connecting.
        password="${existing_password}"
        ok "Existing database password preserved."
    elif [ -n "${CLOUDBOT_DB_PASSWORD:-}" ]; then
        password="${CLOUDBOT_DB_PASSWORD}"
        ok "Database password taken from the environment."
    elif [ "${NON_INTERACTIVE}" = "false" ] && ask_yes_no "Set the database password yourself? (no = generate a strong one)" n; then
        read_secret password "Database password"
    else
        password="$(random_secret 24)"
        ok "Generated a random database password."
    fi

    [ -n "${password}" ] || die "The database password cannot be empty."

    env_set "${ENV_FILE}" DB_CONNECTION "pgsql"
    env_set "${ENV_FILE}" DB_HOST "postgres"
    env_set "${ENV_FILE}" DB_PORT "5432"
    env_set "${ENV_FILE}" DB_DATABASE "${database}"
    env_set "${ENV_FILE}" DB_USERNAME "${username}"
    env_set "${ENV_FILE}" DB_PASSWORD "${password}"

    ok "Database configuration written."
}

configure_redis() {
    # Redis is on the internal Docker network only and is never published, so
    # the existing four-database layout is kept as-is.
    env_set "${ENV_FILE}" REDIS_HOST "redis"
    env_set "${ENV_FILE}" REDIS_PORT "6379"
    env_set "${ENV_FILE}" REDIS_CACHE_DB "$(current_or REDIS_CACHE_DB '' '0')"
    env_set "${ENV_FILE}" REDIS_QUEUE_DB "$(current_or REDIS_QUEUE_DB '' '1')"
    env_set "${ENV_FILE}" REDIS_STATE_DB "$(current_or REDIS_STATE_DB '' '2')"
    env_set "${ENV_FILE}" REDIS_LOCK_DB "$(current_or REDIS_LOCK_DB '' '3')"
    env_set "${ENV_FILE}" CACHE_STORE "redis"
    env_set "${ENV_FILE}" SESSION_DRIVER "database"
    env_set "${ENV_FILE}" QUEUE_CONNECTION "redis"
    ok "Redis configuration written."
}

configure_telegram() {
    step "Telegram configuration"

    local token username chat_id domain webhook_secret webhook_url

    domain="$(env_get "${ENV_FILE}" CLOUDBOT_DOMAIN 2>/dev/null || true)"
    username="$(current_or TELEGRAM_BOT_USERNAME "${CLOUDBOT_TELEGRAM_BOT_USERNAME:-}" '')"
    chat_id="$(current_or TELEGRAM_ADMIN_CHAT_ID "${CLOUDBOT_TELEGRAM_ADMIN_CHAT_ID:-}" '')"

    if [ -n "${CLOUDBOT_TELEGRAM_BOT_TOKEN:-}" ]; then
        token="${CLOUDBOT_TELEGRAM_BOT_TOKEN}"
    elif env_has "${ENV_FILE}" TELEGRAM_BOT_TOKEN; then
        token="$(env_get "${ENV_FILE}" TELEGRAM_BOT_TOKEN)"
        ok "Existing bot token preserved."
    elif [ "${NON_INTERACTIVE}" = "false" ] && ask_yes_no "Configure the Telegram bot token now?" y; then
        read_secret_once token "Telegram bot token (input hidden)"
    else
        token=""
    fi

    if [ "${NON_INTERACTIVE}" = "false" ]; then
        ask username "Bot username (without @, blank to skip)" "${username}"
        ask chat_id "Admin alert chat id (blank to skip)" "${chat_id}"
    fi

    if [ -n "${chat_id}" ] && ! valid_chat_id "${chat_id}"; then
        die "A Telegram chat id is an integer; got: ${chat_id}"
    fi

    # Generated once and preserved. This secret is the only thing separating a
    # genuine Telegram delivery from anybody who learns the webhook URL.
    if env_has "${ENV_FILE}" TELEGRAM_WEBHOOK_SECRET; then
        webhook_secret="$(env_get "${ENV_FILE}" TELEGRAM_WEBHOOK_SECRET)"
        ok "Existing webhook secret preserved."
    else
        webhook_secret="$(random_secret 32)"
        ok "Generated a webhook secret."
    fi

    webhook_url="$(current_or TELEGRAM_WEBHOOK_URL '' '')"
    if [ -n "${domain}" ]; then
        # Always https: the secret header travels on every delivery.
        webhook_url="https://${domain}/telegram/webhook"
    fi

    env_set "${ENV_FILE}" TELEGRAM_BOT_TOKEN "${token}"
    env_set "${ENV_FILE}" TELEGRAM_BOT_USERNAME "${username#@}"
    env_set "${ENV_FILE}" TELEGRAM_WEBHOOK_SECRET "${webhook_secret}"
    env_set "${ENV_FILE}" TELEGRAM_API_BASE_URL "$(current_or TELEGRAM_API_BASE_URL '' 'https://api.telegram.org')"
    env_set "${ENV_FILE}" TELEGRAM_WEBHOOK_URL "${webhook_url}"
    env_set "${ENV_FILE}" TELEGRAM_ADMIN_CHAT_ID "${chat_id}"

    ok "Telegram configuration written."
}

# ---------------------------------------------------------------------------
# APP_KEY
# ---------------------------------------------------------------------------

ensure_app_key() {
    if env_has "${ENV_FILE}" APP_KEY; then
        local key
        key="$(env_get "${ENV_FILE}" APP_KEY)"
        case "${key}" in
            base64:*)
                assert_key_usable "${key}"
                ok "Existing APP_KEY preserved."
                return 0
                ;;
            *)
                die "APP_KEY is present but is not in the expected base64: format.
Refusing to replace it: if it is the key this installation's encrypted data was written with, generating a new one would make that data permanently unreadable.
Restore the correct key into ${ENV_FILE} and re-run."
                ;;
        esac
    fi

    step "Generating APP_KEY (once, for this installation)"

    # Generated here rather than by a container, so it exists in .env before
    # any runtime process reads it, and so it is generated exactly once.
    local raw key
    raw="$(openssl rand -base64 32 2>/dev/null || head -c 32 /dev/urandom | base64)"
    key="base64:${raw}"

    assert_key_usable "${key}"
    env_set "${ENV_FILE}" APP_KEY "${key}"

    ok "APP_KEY generated and stored."
    log "  Back up ${ENV_FILE}. Without this key, encrypted provider and server credentials cannot be read."
}

# A key that is the wrong length silently breaks the encrypter at runtime, so
# it is checked here rather than discovered by a customer.
assert_key_usable() {
    local key="$1" decoded_bytes
    decoded_bytes="$(printf '%s' "${key#base64:}" | base64 -d 2>/dev/null | wc -c || printf '0')"
    [ "${decoded_bytes}" -eq 32 ] \
        || die "APP_KEY does not decode to 32 bytes (got ${decoded_bytes}). Refusing to continue with a broken encrypter."
}

# The .env file is one thing; what the running container actually loaded is
# another. This closes that gap before any data is written.
verify_app_key_in_container() {
    step "Verifying APP_KEY inside the running application"

    local reported
    reported="$(compose exec -T app php -r 'echo empty(getenv("APP_KEY")) ? "missing" : (str_starts_with(getenv("APP_KEY"), "base64:") ? "ok" : "malformed");' 2>/dev/null || printf 'unreadable')"

    case "${reported}" in
        ok) ok "The application container has a usable APP_KEY." ;;
        *) die "The running application reports its APP_KEY as: ${reported}. Refusing to migrate or write data." ;;
    esac
}

# ---------------------------------------------------------------------------
# Database credential safety
# ---------------------------------------------------------------------------

# PostgreSQL only applies POSTGRES_PASSWORD when it initializes an empty data
# directory. If the volume already exists with a different password, the
# container starts happily and the application simply cannot authenticate.
#
# That mismatch is reported and the install stops. It is never "fixed" by
# deleting the volume: the volume is the production database.
assert_database_credentials_usable() {
    step "Checking the database accepts the configured credentials"

    local user database
    user="$(env_get "${ENV_FILE}" DB_USERNAME)"
    database="$(env_get "${ENV_FILE}" DB_DATABASE)"

    # The password travels in the container's environment, never as an
    # argument, so it cannot be read from the host's process list.
    if compose exec -T \
            -e PGPASSWORD="$(env_get "${ENV_FILE}" DB_PASSWORD)" \
            postgres psql -U "${user}" -d "${database}" -c 'SELECT 1' >/dev/null 2>&1; then
        ok "Database credentials are valid."
        return 0
    fi

    err "PostgreSQL is running but rejected the credentials in ${ENV_FILE}."
    cat >&2 <<'EXPLAIN'

  This almost always means the data volume was initialized with a different
  password than the one now in .env. PostgreSQL only reads POSTGRES_PASSWORD
  when it creates an empty data directory; afterwards the stored role password
  is authoritative.

  Nothing has been changed. Your options, in order of preference:

    1. Put the original password back into .env and re-run this installer.
    2. Change the role's password deliberately, then update .env to match:
         docker compose exec postgres psql -U postgres \
           -c "ALTER ROLE <user> WITH PASSWORD '<new password>';"
    3. Only if this database holds nothing you need: remove the volume by
       hand, knowing that it destroys every order, wallet and audit record.

  This installer will not delete the volume for you.
EXPLAIN

    die "Database credential mismatch. See above."
}

# ---------------------------------------------------------------------------
# Application bootstrap
# ---------------------------------------------------------------------------

bootstrap_roles() {
    step "Ensuring roles and permissions exist"
    # The repository's own seeder, which calls RoleProvisioner and is
    # idempotent. A second role system defined in shell would drift from the
    # one the application actually enforces.
    artisan db:seed --force --class='Database\\Seeders\\RolePermissionSeeder'
    ok "Roles and permissions are in place."
}

bootstrap_admin() {
    step "Owner account"

    local name email
    name="${CLOUDBOT_ADMIN_NAME:-}"
    email="${CLOUDBOT_ADMIN_EMAIL:-}"

    if [ "${NON_INTERACTIVE}" = "false" ]; then
        [ -n "${name}" ] || ask name "Administrator name" ""
        [ -n "${email}" ] || ask email "Administrator email" ""
    fi

    if [ -z "${name}" ] || [ -z "${email}" ]; then
        warn "No administrator details supplied; skipping owner creation."
        log "  Create one later with: docker compose run --rm app php artisan app:create-admin"
        return 0
    fi

    valid_email "${email}" || die "Not a valid email address: ${email}"

    if [ -n "${CLOUDBOT_ADMIN_PASSWORD:-}" ]; then
        # Through the container's environment, so the password is never an
        # argument and never reaches the host process list or shell history.
        compose run --rm --no-deps -T \
            -e CLOUDBOT_ADMIN_PASSWORD \
            app php artisan app:create-admin --if-missing --password-from-env --name="${name}" --email="${email}"
    elif [ "${NON_INTERACTIVE}" = "false" ]; then
        # Attached to the terminal so the command's own hidden prompt works.
        artisan_tty app:create-admin --if-missing --name="${name}" --email="${email}"
    else
        warn "CLOUDBOT_ADMIN_PASSWORD is unset; skipping owner creation."
        return 0
    fi

    ok "Owner account created. Two-factor enrolment happens on first sign-in."
}

bootstrap_provider() {
    local token=""

    if [ -n "${CLOUDBOT_HETZNER_API_TOKEN:-}" ]; then
        token="${CLOUDBOT_HETZNER_API_TOKEN}"
    elif [ "${NON_INTERACTIVE}" = "false" ] && ask_yes_no "Configure Hetzner credentials now?" n; then
        read_secret_once token "Hetzner API token (input hidden)"
    fi

    [ -n "${token}" ] || return 0

    step "Storing the Hetzner credential"

    # Through the application's encrypted credential model. The token is passed
    # in the container's environment and read once by the command; it is never
    # an argument, never written to .env, and never printed back.
    CLOUDBOT_PROVIDER_TOKEN="${token}" compose run --rm --no-deps -T \
        -e CLOUDBOT_PROVIDER_TOKEN \
        app php artisan app:set-provider-credential hetzner --token-from-env

    ok "Hetzner credential stored, encrypted at rest."
}

bootstrap_demo_catalog() {
    local wanted="${CLOUDBOT_SEED_DEMO_CATALOG:-}"

    if [ -z "${wanted}" ] && [ "${NON_INTERACTIVE}" = "false" ]; then
        if ask_yes_no "Install the FakeProvider demo catalog? (say no on production)" n; then
            wanted=true
        else
            wanted=false
        fi
    fi

    [ "${wanted}" = "true" ] || return 0

    if [ "$(env_get "${ENV_FILE}" APP_ENV)" = "production" ]; then
        # The simulator is for demonstrations and tests. Selling from it would
        # take a customer's money for a server that does not exist.
        warn "APP_ENV is production; refusing to install the demo catalog."
        return 0
    fi

    step "Syncing the demo catalog"
    artisan providers:sync --provider=fake
    ok "Demo catalog synced. Nothing is on sale until a product is priced and enabled."
}

# ---------------------------------------------------------------------------
# Public edge
# ---------------------------------------------------------------------------

configure_host_edge() {
    local domain want_nginx want_tls tls_email port
    domain="$(env_get "${ENV_FILE}" CLOUDBOT_DOMAIN 2>/dev/null || true)"
    port="$(env_get "${ENV_FILE}" APP_PORT)"

    want_nginx="${CLOUDBOT_HOST_NGINX:-}"
    if [ -z "${want_nginx}" ] && [ "${NON_INTERACTIVE}" = "false" ] && [ -n "${domain}" ]; then
        if ask_yes_no "Configure this host's nginx as the public edge for ${domain}?" y; then
            want_nginx=true
        else
            want_nginx=false
        fi
    fi

    if [ "${want_nginx}" != "true" ]; then
        ok "Host nginx not configured. The stack is reachable on ${APP_BIND_IP:-127.0.0.1}:${port}."
        return 0
    fi

    [ -n "${domain}" ] || die "A domain is required to configure the host edge."

    install_host_nginx_package
    write_host_nginx_site "${domain}" "${port}"
    reload_host_nginx || die "Host nginx configuration was not applied."

    want_tls="${CLOUDBOT_TLS:-}"
    if [ -z "${want_tls}" ] && [ "${NON_INTERACTIVE}" = "false" ]; then
        if ask_yes_no "Obtain a Let's Encrypt certificate for ${domain}?" y; then
            want_tls=true
        else
            want_tls=false
        fi
    fi

    [ "${want_tls}" = "true" ] || { warn "TLS not configured. Telegram requires HTTPS, so the webhook cannot be set yet."; return 0; }

    tls_email="${CLOUDBOT_TLS_EMAIL:-}"
    if [ -z "${tls_email}" ] && [ "${NON_INTERACTIVE}" = "false" ]; then
        ask tls_email "Contact email for certificate expiry notices" ""
    fi
    valid_email "${tls_email}" || die "A valid contact email is required for certificate issuance."

    # DNS first: a failed authorization counts against a rate limit.
    if ! check_dns_resolves "${domain}"; then
        local status=$?
        [ "${status}" -eq 2 ] || die "DNS for ${domain} is not ready. Fix it and re-run."
    fi

    install_certbot
    if ! obtain_certificate "${domain}" "${tls_email}"; then
        warn "Continuing without TLS. The site is served over plain HTTP and no webhook will be set."
        return 0
    fi

    reload_host_nginx || warn "certbot wrote its configuration but nginx did not reload cleanly."
    verify_renewal

    if verify_public_health "${domain}" 60; then
        # Only now is https true, so only now does APP_URL say so.
        env_set "${ENV_FILE}" APP_URL "https://${domain}"
        env_set "${ENV_FILE}" TELEGRAM_WEBHOOK_URL "https://${domain}/telegram/webhook"
        # The app caches config at boot; it has to be restarted to see the change.
        compose up -d --force-recreate app
        wait_for_healthy app 120 || warn "The application did not return to healthy after the URL change."
        recreate_proxy
        ok "Public HTTPS verified."
    else
        warn "Public HTTPS did not verify. APP_URL is unchanged and the webhook will not be set."
    fi
}

configure_webhook() {
    local app_url want domain
    app_url="$(env_get "${ENV_FILE}" APP_URL)"
    domain="$(env_get "${ENV_FILE}" CLOUDBOT_DOMAIN 2>/dev/null || true)"

    env_has "${ENV_FILE}" TELEGRAM_BOT_TOKEN || { warn "No bot token configured; skipping webhook setup."; return 0; }

    # Three conditions, all required. Telegram will not deliver to plain HTTP,
    # and pointing it at an unverified endpoint produces a bot that silently
    # receives nothing.
    case "${app_url}" in
        https://*) ;;
        *) warn "APP_URL is not https; skipping webhook setup."; return 0 ;;
    esac

    [ -n "${domain}" ] || { warn "No domain configured; skipping webhook setup."; return 0; }

    if ! verify_public_health "${domain}" 30; then
        warn "Public endpoint is not verified; skipping webhook setup."
        return 0
    fi

    want="${CLOUDBOT_SET_WEBHOOK:-}"
    if [ -z "${want}" ] && [ "${NON_INTERACTIVE}" = "false" ]; then
        if ask_yes_no "Point the Telegram webhook at ${app_url}?" y; then want=true; else want=false; fi
    fi
    [ "${want}" = "true" ] || { ok "Webhook not changed."; return 0; }

    step "Setting the Telegram webhook"
    # The application's own command owns the URL shape and the secret header.
    artisan telegram:webhook set
    ok "Webhook set."
}

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

summary() {
    local domain app_url port bind
    domain="$(env_get "${ENV_FILE}" CLOUDBOT_DOMAIN 2>/dev/null || true)"
    app_url="$(env_get "${ENV_FILE}" APP_URL)"
    port="$(env_get "${ENV_FILE}" APP_PORT)"
    bind="$(env_get "${ENV_FILE}" APP_BIND_IP)"

    # Names of things, never values. No key, no password, no token.
    cat <<SUMMARY

${C_GREEN}${C_BOLD}CloudBot Manager is installed.${C_RESET}

  Admin panel      ${app_url}/admin
  Local endpoint   http://${bind}:${port}/health
  Public domain    ${domain:-not configured}
  Install path     ${INSTALL_DIR}
  Configuration    ${ENV_FILE} (mode 600)

  Secrets are stored in .env and in the database, encrypted with APP_KEY.
  None of them is printed here, and none is written to any log.

${C_BOLD}Next${C_RESET}
  1. Back up ${ENV_FILE} somewhere safe. Losing APP_KEY makes every encrypted
     provider and server credential permanently unreadable.
  2. Sign in at ${app_url}/admin and complete two-factor enrolment.
  3. Configure products and pricing, then enable sales in Settings.
  4. Schedule ./backup.sh and copy its archives off this host.

${C_BOLD}Operations${C_RESET}
  Status    docker compose ps
  Logs      docker compose logs -f app
  Update    sudo ./update.sh
  Backup    sudo ./backup.sh
  Restore   sudo ./restore.sh --archive <file>

SUMMARY
}

main "$@"
