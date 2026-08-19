#!/usr/bin/env bash
#
# CloudBot Manager — install.sh
#
# Idempotent, Git-aware installer for a clean Ubuntu server.
#
#   git clone https://github.com/Mhoseinshah1/cloudbot-manager.git /opt/cloudbot-manager
#   cd /opt/cloudbot-manager
#   sudo ./install.sh
#
# Safe to run multiple times: existing data (.env, storage, PostgreSQL/Redis
# volumes) is always preserved.
#
# Configuration is provided through environment variables (all optional):
#   APP_DIR       target directory (default: /opt/cloudbot-manager)
#   GIT_REPO      repository URL used when bootstrapping from a fresh clone
#   INSTALL_TAG   Git tag/release to pin (e.g. v1.0.0); default: main
#   APP_PORT      host port for Nginx (default: 8080)
#   DB_PASSWORD   database password (prompted securely when omitted)
#   FORCE_INSTALL allow install into an existing repo with a different origin
#   SEED          set to 1 to seed demo data after migrating (admin@example.com)
#
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/cloudbot-manager}"
GIT_REPO="${GIT_REPO:-https://github.com/Mhoseinshah1/cloudbot-manager.git}"
INSTALL_TAG="${INSTALL_TAG:-}"
APP_PORT="${APP_PORT:-8080}"
FORCE_INSTALL="${FORCE_INSTALL:-0}"
SEED="${SEED:-0}"

SUPPORTED_UBUNTU="22.04 24.04"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || echo /dev/stdin)"

log()  { printf '\033[1;34m[install]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# 1. Root / sudo check
# ---------------------------------------------------------------------------
require_root() {
    if [ "$(id -u)" -eq 0 ]; then
        return
    fi
    if command -v sudo >/dev/null 2>&1; then
        log "Re-executing with sudo…"
        exec sudo -E env APP_DIR="$APP_DIR" GIT_REPO="$GIT_REPO" INSTALL_TAG="$INSTALL_TAG" \
            APP_PORT="$APP_PORT" DB_PASSWORD="${DB_PASSWORD:-}" FORCE_INSTALL="$FORCE_INSTALL" \
            SEED="$SEED" bash "$0" "$@"
    fi
    err "This script must run as root (or with sudo)."
}

# ---------------------------------------------------------------------------
# 2. Ubuntu version check
# ---------------------------------------------------------------------------
check_os() {
    . /etc/os-release
    if [ "${ID:-}" != "ubuntu" ]; then
        err "Unsupported distribution '${ID:-unknown}'. Only Ubuntu is supported."
    fi
    case " $SUPPORTED_UBUNTU " in
        *" ${VERSION_ID:-} "*) ;;
        *) err "Unsupported Ubuntu version '${VERSION_ID:-unknown}'. Supported:${SUPPORTED_UBUNTU}." ;;
    esac
    log "Ubuntu ${VERSION_ID} detected."
}

# ---------------------------------------------------------------------------
# 3. Helpers
# ---------------------------------------------------------------------------
set_dotenv() {
    local key="$1" value="$2" file="$APP_DIR/.env"
    if grep -q "^${key}=" "$file" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '\n%s=%s\n' "$key" "$value" >> "$file"
    fi
}

dotenv_get() {
    grep -E "^${1}=" "$APP_DIR/.env" 2>/dev/null | head -n1 | cut -d= -f2- || true
}

wait_for_health() {
    local port="$1" attempts="${2:-30}"
    log "Waiting for the application to become healthy on port ${port}…"
    for _ in $(seq 1 "$attempts"); do
        if curl -fsS "http://127.0.0.1:${port}/health" >/dev/null 2>&1; then
            log "Application is healthy."
            return 0
        fi
        sleep 2
    done
    return 1
}

wait_for_compose_service() {
    local service="$1" attempts="${2:-60}"
    for _ in $(seq 1 "$attempts"); do
        local id
        id="$(docker compose ps -q "$service" 2>/dev/null || true)"
        if [ -n "$id" ]; then
            state="$(docker inspect -f '{{.State.Health.Status}}' "$id" 2>/dev/null || echo starting)"
            if [ "$state" = "healthy" ]; then
                return 0
            fi
        fi
        sleep 2
    done
    return 1
}

# ---------------------------------------------------------------------------
# 4. Git, Docker Engine and Compose plugin installation (idempotent)
# ---------------------------------------------------------------------------
install_system_packages() {
    if command -v git >/dev/null 2>&1; then
        log "Git already installed."
    else
        log "Installing Git…"
        apt-get update -qq
        apt-get install -y -qq git
    fi

    if command -v docker >/dev/null 2>&1; then
        log "Docker Engine already installed."
    else
        log "Installing Docker Engine from the official repository…"
        apt-get update -qq
        apt-get install -y -qq ca-certificates curl gnupg
        install -m 0755 -d /etc/apt/keyrings
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        chmod a+r /etc/apt/keyrings/docker.gpg
        . /etc/os-release
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
            > /etc/apt/sources.list.d/docker.list
        apt-get update -qq
        apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
        systemctl enable --now docker
    fi

    if docker compose version >/dev/null 2>&1; then
        log "Docker Compose plugin already available."
    else
        err "Docker Compose plugin is required. Install docker-compose-plugin and re-run."
    fi
}

# ---------------------------------------------------------------------------
# 5. Repository bootstrap (Git-aware)
# ---------------------------------------------------------------------------
bootstrap_repo() {
    mkdir -p "$APP_DIR"

    if [ -d "$APP_DIR/.git" ]; then
        log "Existing Git repository detected at ${APP_DIR}."
        local origin
        origin="$(git -C "$APP_DIR" remote get-url origin 2>/dev/null || true)"
        if [ -n "${GIT_REPO}" ] && [ "${GIT_REPO}" != "${origin}" ] && [ "${FORCE_INSTALL}" != "1" ]; then
            err "Existing origin '${origin}' differs from GIT_REPO '${GIT_REPO}'. Set FORCE_INSTALL=1 to continue."
        fi
        log "Fetching latest from origin…"
        git -C "$APP_DIR" fetch origin --tags --prune
        if [ -n "$INSTALL_TAG" ]; then
            log "Checking out release tag ${INSTALL_TAG}…"
            git -C "$APP_DIR" checkout "tags/${INSTALL_TAG}" --force
        else
            git -C "$APP_DIR" checkout main --force
            git -C "$APP_DIR" merge --ff-only origin/main || true
        fi
        return
    fi

    if [ -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]; then
        err "${APP_DIR} exists but is not a Git repository and is not empty. Refusing to overwrite data."
    fi

    # Running from a checkout? Copy it (preserving .git) instead of re-cloning.
    if [ -f "$SCRIPT_DIR/install.sh" ] && [ -d "$SCRIPT_DIR/.git" ]; then
        log "Copying repository into ${APP_DIR}…"
        cp -a "$SCRIPT_DIR/." "$APP_DIR/"
    else
        log "Cloning ${GIT_REPO} into ${APP_DIR}…"
        git clone "${GIT_REPO}" "$APP_DIR"
    fi

    cd "$APP_DIR"
    if [ -n "$INSTALL_TAG" ]; then
        log "Pinning release tag ${INSTALL_TAG}…"
        git checkout "tags/${INSTALL_TAG}" --force
    fi
}

# ---------------------------------------------------------------------------
# 6. Environment configuration
# ---------------------------------------------------------------------------
configure_env() {
    if [ -f "$APP_DIR/.env" ]; then
        log "Existing .env preserved."
    else
        log "Creating .env from .env.example…"
        [ -f "$APP_DIR/.env.example" ] || err ".env.example is missing from the repository."
        cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    fi

    # APP_PORT for the Compose port mapping.
    if [ -z "$(dotenv_get APP_PORT)" ]; then
        set_dotenv APP_PORT "$APP_PORT"
    fi

    # Database password: prefer env, else prompt securely, else warn.
    if [ -n "${DB_PASSWORD:-}" ]; then
        set_dotenv DB_PASSWORD "$DB_PASSWORD"
    elif [ -z "$(dotenv_get DB_PASSWORD)" ] || [ "$(dotenv_get DB_PASSWORD)" = "change-me" ]; then
        if [ -t 0 ]; then
            printf 'Database password (input is hidden): '
            read -r -s DB_PASSWORD_INPUT
            printf '\n'
            [ -n "$DB_PASSWORD_INPUT" ] || err "A database password is required."
            set_dotenv DB_PASSWORD "$DB_PASSWORD_INPUT"
        else
            warn "DB_PASSWORD not provided and running non-interactively; using a generated password."
            set_dotenv DB_PASSWORD "$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)"
        fi
    fi

    log "Configuration summary:"
    log "  APP_DIR=${APP_DIR}  APP_PORT=$(dotenv_get APP_PORT)  DB_DATABASE=$(dotenv_get DB_DATABASE)  DB_USERNAME=$(dotenv_get DB_USERNAME)"
}

# ---------------------------------------------------------------------------
# 7. Permissions, images, containers, key, migrations
# ---------------------------------------------------------------------------
provision() {
    cd "$APP_DIR"

    log "Setting file permissions for the php-fpm container…"
    mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
    chown -R 33:33 storage bootstrap/cache 2>/dev/null || true

    log "Building images…"
    docker compose build

    # Vendor must exist on the bind mount for the app container to boot.
    if [ ! -d "$APP_DIR/vendor" ]; then
        log "Installing Composer dependencies…"
        docker compose run --rm --no-deps app composer install --no-dev --no-interaction --prefer-dist
    fi

    log "Starting services…"
    docker compose up -d --remove-orphans

    if [ -z "$(dotenv_get APP_KEY)" ]; then
        log "Generating Laravel APP_KEY…"
        docker compose run --rm --no-deps app php artisan key:generate --force
    fi

    log "Waiting for PostgreSQL to become healthy…"
    wait_for_compose_service postgres 60

    log "Running migrations…"
    docker compose exec -T app php artisan migrate --force

    if [ "$SEED" = "1" ]; then
        log "Seeding database (admin@example.com / password — change immediately)…"
        docker compose exec -T app php artisan db:seed --force
    fi
}

# ---------------------------------------------------------------------------
# 8. Health check + status
# ---------------------------------------------------------------------------
verify() {
    cd "$APP_DIR"
    if ! wait_for_health "$(dotenv_get APP_PORT)"; then
        err "Health check failed. Inspect with: cd ${APP_DIR} && docker compose logs --tail=200 app"
    fi
    log "Installation complete."
    echo
    docker compose ps
    echo
    log "Application:  http://$(hostname -I 2>/dev/null | awk '{print $1}')/  (port $(dotenv_get APP_PORT))"
    log "Health check: http://127.0.0.1:$(dotenv_get APP_PORT)/health"
    log "Admin panel:  http://127.0.0.1:$(dotenv_get APP_PORT)/admin   (seed an admin with: cd ${APP_DIR} && SEED=1 ./install.sh)"
}

# ---------------------------------------------------------------------------
main() {
    require_root "$@"
    check_os
    install_system_packages
    bootstrap_repo
    configure_env
    provision
    verify
}

main "$@"
