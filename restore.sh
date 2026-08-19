#!/usr/bin/env bash
#
# CloudBot Manager — restore.sh
#
# Restores a backup produced by backup.sh:
#   sudo ./restore.sh                     # latest backup in BACKUP_DIR
#   sudo ./restore.sh backups/db_20260101_000000.sql.gz backups/app_20260101_000000.tar.gz
#
# Restores .env + persistent storage + the PostgreSQL dump, then restarts the
# stack and validates health. The Git working tree is not modified.
#
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/cloudbot-manager}"
BACKUP_DIR="${BACKUP_DIR:-${APP_DIR}/backups}"
DB_FILE="${1:-}"
APP_FILE="${2:-}"

log()  { printf '\033[1;34m[restore]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
err()  { printf '\033[1;31m[error]\033[0m %s\n' "$*" >&2; exit 1; }

require_root() {
    [ "$(id -u)" -eq 0 ] && return
    if command -v sudo >/dev/null 2>&1; then
        exec sudo -E env APP_DIR="$APP_DIR" BACKUP_DIR="$BACKUP_DIR" DB_FILE="$DB_FILE" APP_FILE="$APP_FILE" bash "$0" "$@"
    fi
    err "This script must run as root (or with sudo)."
}

dotenv_get() {
    grep -E "^${1}=" "$APP_DIR/.env" 2>/dev/null | head -n1 | cut -d= -f2- || true
}

latest_file() { ls -1t "$BACKUP_DIR"/"$1" 2>/dev/null | head -n1 || true; }

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

main() {
    require_root "$@"

    [ -d "$APP_DIR" ] || err "No installation found at ${APP_DIR}."
    command -v docker >/dev/null 2>&1 || err "Docker is not installed."

    DB_FILE="${DB_FILE:-$(latest_file 'db_*.sql.gz')}"
    APP_FILE="${APP_FILE:-$(latest_file 'app_*.tar.gz')}"

    [ -n "$DB_FILE" ] && [ -f "$DB_FILE" ] || err "Database backup not found. Pass the db_*.sql.gz path explicitly."
    [ -n "$APP_FILE" ] && [ -f "$APP_FILE" ] || err "App backup not found. Pass the app_*.tar.gz path explicitly."

    DB_DATABASE="$(dotenv_get DB_DATABASE)"
    DB_USERNAME="$(dotenv_get DB_USERNAME)"
    DB_DATABASE="${DB_DATABASE:-vps_platform}"
    DB_USERNAME="${DB_USERNAME:-vps}"

    log "Stopping application services…"
    docker compose -f "$APP_DIR/docker-compose.yml" stop app queue scheduler 2>/dev/null || true

    log "Restoring configuration and persistent data from ${APP_FILE}…"
    tar xzf "$APP_FILE" -C "$APP_DIR" .env docker-compose.yml docker storage 2>/dev/null || true

    log "Restoring PostgreSQL database from ${DB_FILE}…"
    gunzip -c "$DB_FILE" | docker compose -f "$APP_DIR/docker-compose.yml" exec -T postgres \
        psql -U "$DB_USERNAME" -d "$DB_DATABASE"

    log "Fixing permissions…"
    chown -R 33:33 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" 2>/dev/null || true

    log "Starting services…"
    docker compose -f "$APP_DIR/docker-compose.yml" up -d --remove-orphans

    log "Validating health…"
    if wait_for_health "$(dotenv_get APP_PORT)"; then
        log "Restore completed successfully."
    else
        warn "Health check failed after restore. Inspect with: cd ${APP_DIR} && docker compose logs --tail=200 app"
        exit 1
    fi
}

main "$@"
