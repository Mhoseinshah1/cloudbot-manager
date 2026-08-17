#!/usr/bin/env bash
#
# VPS Platform — backup.sh
#
# Creates a timestamped backup containing:
#   - PostgreSQL database (pg_dump, gzip)
#   - persistent application data (Laravel storage/)
#   - relevant configuration (.env, docker-compose.yml, docker/ configs)
#
# Backups are written to BACKUP_DIR (default: ${APP_DIR}/backups), which is
# Git-ignored — secrets never enter the repository.
#
#   sudo ./backup.sh            # full backup
#   sudo ./backup.sh --silent   # quiet (used by update.sh)
#
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/vps-platform}"
BACKUP_DIR="${BACKUP_DIR:-${APP_DIR}/backups}"
SILENT=0
[ "${1:-}" = "--silent" ] && SILENT=1

log()  { [ "$SILENT" = "1" ] || printf '\033[1;34m[backup]\033[0m %s\n' "$*"; }

require_root() {
    [ "$(id -u)" -eq 0 ] && return
    if command -v sudo >/dev/null 2>&1; then
        exec sudo -E env APP_DIR="$APP_DIR" BACKUP_DIR="$BACKUP_DIR" bash "$0" "$@"
    fi
    echo "[backup] This script must run as root (or with sudo)." >&2
    exit 1
}

dotenv_get() {
    grep -E "^${1}=" "$APP_DIR/.env" 2>/dev/null | head -n1 | cut -d= -f2- || true
}

main() {
    require_root "$@"

    [ -d "$APP_DIR/.git" ] || { echo "[backup] Not an installation (missing ${APP_DIR}/.git)." >&2; exit 1; }
    [ -f "$APP_DIR/.env" ] || { echo "[backup] Missing ${APP_DIR}/.env." >&2; exit 1; }
    command -v docker >/dev/null 2>&1 || { echo "[backup] Docker is not installed." >&2; exit 1; }

    mkdir -p "$BACKUP_DIR"
    TS="$(date +%Y%m%d_%H%M%S)"
    DB_FILE="${BACKUP_DIR}/db_${TS}.sql.gz"
    APP_FILE="${BACKUP_DIR}/app_${TS}.tar.gz"

    DB_DATABASE="$(dotenv_get DB_DATABASE)"
    DB_USERNAME="$(dotenv_get DB_USERNAME)"
    DB_DATABASE="${DB_DATABASE:-vps_platform}"
    DB_USERNAME="${DB_USERNAME:-vps}"

    log "Backing up PostgreSQL database '${DB_DATABASE}'…"
    docker compose -f "$APP_DIR/docker-compose.yml" exec -T postgres \
        pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" | gzip > "$DB_FILE"
    [ -s "$DB_FILE" ] || { echo "[backup] Database dump is empty — aborting." >&2; rm -f "$DB_FILE"; exit 1; }

    log "Backing up persistent application data and configuration…"
    tar czf "$APP_FILE" \
        --exclude='./backups' \
        --exclude='./vendor' \
        --exclude='./node_modules' \
        --exclude='./.git' \
        --exclude='./storage/logs/*.log' \
        -C "$APP_DIR" \
        .env docker-compose.yml docker storage 2>/dev/null || true

    log "Backup complete:"
    log "  ${DB_FILE}   ($(du -h "$DB_FILE" | cut -f1))"
    log "  ${APP_FILE}  ($(du -h "$APP_FILE" | cut -f1))"
}

main "$@"
