#!/usr/bin/env bash
#
# VPS Platform — update.sh
#
# Updates an existing installation from its Git origin:
#   - acquires an update lock (single updater at a time)
#   - creates a backup before touching anything
#   - updates to the configured release/tag (INSTALL_TAG) or origin/main
#   - rebuilds containers only when needed
#   - runs migrations safely (additive `migrate --force`)
#   - restarts affected services and verifies health
#   - rolls back to the previous revision when deployment validation fails
#
# Never deletes PostgreSQL/Redis persistent volumes.
#
#   sudo ./update.sh            # update to origin/main (or the pinned tag)
#   sudo INSTALL_TAG=v1.2.0 ./update.sh   # pin and update to a specific release
#
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/vps-platform}"
INSTALL_TAG="${INSTALL_TAG:-}"
SKIP_BACKUP="${SKIP_BACKUP:-0}"
LOCK_FILE="${APP_DIR}/.deploy/update.lock"
DEPLOY_LOG="${APP_DIR}/.deploy/update.log"

log()  { printf '\033[1;34m[update]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

require_root() {
    [ "$(id -u)" -eq 0 ] && return
    if command -v sudo >/dev/null 2>&1; then
        exec sudo -E env APP_DIR="$APP_DIR" INSTALL_TAG="${INSTALL_TAG:-}" \
            SKIP_BACKUP="$SKIP_BACKUP" bash "$0" "$@"
    fi
    err "This script must run as root (or with sudo)."
}

dotenv_get() {
    grep -E "^${1}=" "$APP_DIR/.env" 2>/dev/null | head -n1 | cut -d= -f2- || true
}

wait_for_health() {
    local port="$1" attempts="${2:-40}"
    for _ in $(seq 1 "$attempts"); do
        if curl -fsS "http://127.0.0.1:${port}/health" >/dev/null 2>&1; then
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

acquire_lock() {
    mkdir -p "$(dirname "$LOCK_FILE")"
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        err "Another update is already in progress (${LOCK_FILE})."
    fi
    log "Update lock acquired."
}

verify_installation() {
    [ -d "$APP_DIR/.git" ] || err "No Git repository found at ${APP_DIR}. Run install.sh first."
    [ -f "$APP_DIR/.env" ] || err "Missing ${APP_DIR}/.env. Run install.sh first."
    command -v docker >/dev/null 2>&1 || err "Docker is not installed. Run install.sh first."
    cd "$APP_DIR"
}

target_ref() {
    if [ -n "$INSTALL_TAG" ]; then
        git rev-parse --verify "tags/${INSTALL_TAG}" >/dev/null 2>&1 || err "Tag '${INSTALL_TAG}' does not exist."
        printf 'tags/%s' "$INSTALL_TAG"
    else
        printf 'origin/main'
    fi
}

REBUILT=0

deploy_revision() {
    local ref="$1"
    # Diff against the previously deployed revision (computed before checkout).
    local changed
    changed="$(git diff --name-only "$PREV" "$ref")"

    log "Deploying ${ref}…"
    git checkout "$ref" --force

    # Rebuild only when infrastructure/dependency files changed.
    if printf '%s\n' "$changed" | grep -qE '^(Dockerfile|docker-compose.yml|composer\.json|composer\.lock|\.env\.example)$'; then
        log "Infrastructure or dependency files changed — rebuilding images…"
        docker compose build
        REBUILT=1
    fi

    if printf '%s\n' "$changed" | grep -q '^composer\.lock$' || [ ! -d vendor ]; then
        log "Synchronizing Composer dependencies…"
        docker compose run --rm --no-deps app composer install --no-dev --no-interaction --prefer-dist
    fi

    docker compose up -d --remove-orphans
    wait_for_compose_service postgres 60

    log "Running migrations…"
    docker compose exec -T app php artisan migrate --force
    docker compose restart app queue scheduler
}

main() {
    require_root "$@"
    verify_installation
    acquire_lock

    mkdir -p "$(dirname "$DEPLOY_LOG")"
    exec > >(tee -a "$DEPLOY_LOG") 2>&1

    if [ "$SKIP_BACKUP" != "1" ]; then
        log "Creating pre-update backup…"
        "$APP_DIR/backup.sh" --silent || warn "Backup failed; continuing anyway (set SKIP_BACKUP=1 to skip entirely)."
    fi

    PREV="$(git rev-parse HEAD)"

    log "Fetching from origin…"
    git fetch origin --tags --prune

    TARGET="$(target_ref)"
    log "Current revision: $(git rev-parse --short "$PREV")"
    log "Target revision:  ${TARGET}"

    if [ "$(git rev-parse "$TARGET")" = "$PREV" ]; then
        log "Already at the target revision. Nothing to do."
        exit 0
    fi

    if deploy_revision "$TARGET"; then
        log "Validating health…"
        if wait_for_health "$(dotenv_get APP_PORT)"; then
            log "Update to ${TARGET} completed successfully."
            exit 0
        fi
        warn "Health check failed after update."
    else
        warn "Deployment of ${TARGET} failed."
    fi

    # ---- Rollback ----
    warn "Rolling back to ${PREV}…"
    git checkout "$PREV" --force

    if [ "$REBUILT" = "1" ]; then
        log "Rebuilding images for the previous revision…"
        docker compose build
    fi
    docker compose up -d --remove-orphans
    docker compose restart app queue scheduler

    if wait_for_health "$(dotenv_get APP_PORT)"; then
        warn "Rollback to ${PREV} succeeded. The application is healthy on the previous revision."
        warn "Note: database migrations applied during the failed update are not automatically reverted."
        exit 1
    fi

    err "Rollback also failed — manual intervention required. Logs: ${DEPLOY_LOG}"
}

main "$@"
