#!/usr/bin/env bash
#
# CloudBot Manager restore.
#
#   sudo ./restore.sh --archive /path/cloudbot-<stamp>.tar.gz.age
#   sudo ./restore.sh --archive <file> --yes        # non-interactive
#
# THIS REPLACES THE LIVE DATABASE. Every order, wallet balance, ledger row and
# audit entry written since the archive was taken is gone afterwards.
#
# Because of that, nothing here is implicit:
#
#   * the archive is decrypted, unpacked and inspected before anything stops;
#   * its manifest must say it belongs to this application;
#   * the current database is backed up first, unless that is impossible;
#   * an interactive operator types a confirmation phrase, not "y";
#   * a non-interactive run must pass --yes explicitly;
#   * the volumes are never removed — the database is restored into the
#     running PostgreSQL, not by deleting and recreating it.
#
# Every rm guards its variables with :? so an unset path aborts rather than
# expanding into something catastrophic.

set -Eeuo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly INSTALL_DIR
readonly ENV_FILE="${INSTALL_DIR}/.env"
readonly LOCK_FILE="/var/lock/cloudbot-manager-deploy.lock"
readonly CONFIRM_PHRASE="replace the database"

# shellcheck source=scripts/lib/common.sh
. "${INSTALL_DIR}/scripts/lib/common.sh"
# shellcheck source=scripts/lib/stack.sh
. "${INSTALL_DIR}/scripts/lib/stack.sh"

ARCHIVE=""
ASSUME_YES=false
RESTORE_ENV=false
PASSPHRASE_FILE="${CLOUDBOT_BACKUP_PASSPHRASE_FILE:-/etc/cloudbot-manager/backup.passphrase}"
WORK_DIR=""
SAFETY_ARCHIVE=""
KEY_FILE_IS_TEMPORARY=""

usage() {
    cat <<'USAGE'
Usage: sudo ./restore.sh --archive <file> [options]

  --archive <file>      The encrypted backup to restore. Required.
  --passphrase-file <f> Key file (age identity or gpg passphrase).
                        Default /etc/cloudbot-manager/backup.passphrase
  --yes                 Proceed without the interactive confirmation phrase.
                        Required for any non-interactive run. Never a default.
  --restore-env         Also replace .env with the archived one. Off by
                        default: the archived APP_KEY may differ from this
                        host's, and replacing it changes what can be decrypted.
  --help                Show this message.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --archive) [ $# -ge 2 ] || die "--archive needs a value."; ARCHIVE="$2"; shift 2 ;;
        --passphrase-file) [ $# -ge 2 ] || die "--passphrase-file needs a value."; PASSPHRASE_FILE="$2"; shift 2 ;;
        --yes) ASSUME_YES=true; shift ;;
        --restore-env) RESTORE_ENV=true; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; die "Unknown argument: $1" ;;
    esac
done

umask 077

# The decrypted payload is the entire production database in plaintext. It must
# not survive this process under any exit path.
cleanup() {
    if [ -n "${KEY_FILE_IS_TEMPORARY}" ] && [ -f "${KEY_FILE_IS_TEMPORARY}" ]; then
        rm -f -- "${KEY_FILE_IS_TEMPORARY:?}"
    fi
    if [ -n "${WORK_DIR}" ] && [ -d "${WORK_DIR}" ]; then
        rm -rf -- "${WORK_DIR:?}"
    fi
    return 0
}
trap cleanup EXIT INT TERM

main() {
    require_root
    take_lock "${LOCK_FILE}"
    require_docker

    [ -n "${ARCHIVE}" ] || { usage >&2; die "--archive is required."; }
    [ -f "${ENV_FILE}" ] || die "No .env found in ${INSTALL_DIR}. This does not look like an installation to restore into."

    validate_archive_path
    local encryptor key_file
    encryptor="$(detect_encryptor "${ARCHIVE}")"
    key_file="$(resolve_key_file)"

    step "Decrypting and inspecting the archive"
    WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cloudbot-restore.XXXXXX")"
    chmod 700 "${WORK_DIR}"
    unpack_archive "${ARCHIVE}" "${encryptor}" "${key_file}"
    verify_payload
    inspect_manifest

    confirm_destruction

    step "Stopping the application and workers"
    # Stopped, not removed. No volume is touched at any point.
    stop_workers
    compose stop app nginx
    ok "Application stopped. PostgreSQL and Redis are still running."

    take_safety_backup

    step "Restoring the database"
    restore_database

    if [ "${RESTORE_ENV}" = "true" ]; then
        restore_env
    else
        log "  .env left as it is. Re-run with --restore-env if the archived configuration is the one you want."
    fi

    restore_storage

    step "Starting the application"
    compose up -d app
    wait_for_healthy app 180 || die "The application did not become healthy after the restore."
    recreate_proxy
    start_workers

    step "Verifying"
    verify_local_health 120 || die "The restore completed but the health endpoint is not green."

    final_report
}

# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------

validate_archive_path() {
    [ -f "${ARCHIVE}" ] || die "No such archive: ${ARCHIVE}"
    [ -r "${ARCHIVE}" ] || die "Cannot read: ${ARCHIVE}"
    [ -s "${ARCHIVE}" ] || die "The archive is empty: ${ARCHIVE}"

    ARCHIVE="$(cd "$(dirname "${ARCHIVE}")" && pwd)/$(basename "${ARCHIVE}")"
}

detect_encryptor() {
    local archive="$1"
    case "${archive}" in
        *.age) have age || die "This archive is age-encrypted but age is not installed."; printf 'age' ;;
        *.gpg) have gpg || die "This archive is gpg-encrypted but gpg is not installed."; printf 'gpg' ;;
        *) die "Unrecognised archive format: ${archive}
Expected a file produced by backup.sh, ending in .age or .gpg." ;;
    esac
}

# The key is handed to the decryptor as a path — an age identity for age, a
# passphrase for gpg — so nothing secret becomes an argument.
#
# When no key file exists, a passphrase can still be typed for a gpg archive;
# it is written to a mode-600 file that the exit trap removes. An age identity
# is far too long to type, so that case asks for the file instead.
resolve_key_file() {
    local typed tmp

    if [ -r "${PASSPHRASE_FILE}" ]; then
        [ -s "${PASSPHRASE_FILE}" ] || die "${PASSPHRASE_FILE} is empty."
        printf '%s' "${PASSPHRASE_FILE}"
        return 0
    fi

    case "${ARCHIVE}" in
        *.age) die "No key file at ${PASSPHRASE_FILE}.
This archive is age-encrypted, so restoring it needs the age identity it was written with. Pass it with --passphrase-file." ;;
    esac

    interactive || die "No key file at ${PASSPHRASE_FILE} and no terminal to ask on."

    read_secret_once typed "Backup passphrase (input hidden)"
    [ -n "${typed}" ] || die "The passphrase cannot be empty."

    tmp="${WORK_DIR:-${TMPDIR:-/tmp}}/restore-key"
    ( umask 077; printf '%s' "${typed}" > "${tmp}" )
    chmod 600 "${tmp}"
    KEY_FILE_IS_TEMPORARY="${tmp}"

    printf '%s' "${tmp}"
}

unpack_archive() {
    local archive="$1" encryptor="$2" key_file="$3"

    case "${encryptor}" in
        age) age --decrypt --identity "${key_file}" "${archive}" ;;
        gpg) gpg --batch --quiet --decrypt --passphrase-file "${key_file}" "${archive}" ;;
    esac | tar -xzf - -C "${WORK_DIR}" \
        || die "Could not decrypt and unpack the archive. Wrong key, or the file is damaged."

    ok "Archive decrypted."
}

# Integrity first, contents second. A truncated or tampered archive must be
# rejected before it replaces anything.
verify_payload() {
    local required missing=()

    for required in manifest.json database.dump SHA256SUMS; do
        [ -f "${WORK_DIR}/${required}" ] || missing+=("${required}")
    done

    [ "${#missing[@]}" -eq 0 ] || die "The archive is missing required parts: ${missing[*]}
This is not a complete CloudBot Manager backup; refusing to restore from it."

    ( cd "${WORK_DIR}" && sha256sum --quiet --check SHA256SUMS ) \
        || die "Checksum verification failed. The archive is damaged or was modified; refusing to restore."

    head -c 5 "${WORK_DIR}/database.dump" | grep -q 'PGDMP' \
        || die "The database payload is not a PostgreSQL custom-format dump; refusing to restore."

    ok "Integrity verified: checksums match and the dump is a valid PostgreSQL archive."
}

# A backup taken from a different installation would silently replace this
# one's data with somebody else's. The manifest is checked, and a mismatch
# requires the operator to say so out loud.
inspect_manifest() {
    local manifest application created source_revision archived_db current_db archived_env

    manifest="${WORK_DIR}/manifest.json"
    application="$(json_field "${manifest}" application)"
    created="$(json_field "${manifest}" created_at)"
    source_revision="$(json_field "${manifest}" source_revision)"
    archived_db="$(json_field "${manifest}" database)"
    archived_env="$(json_field "${manifest}" app_env)"
    current_db="$(env_get "${ENV_FILE}" DB_DATABASE)"

    [ "${application}" = "cloudbot-manager" ] \
        || die "This archive says it belongs to '${application}', not cloudbot-manager. Refusing to restore."

    cat <<DETAILS

  Archive       ${ARCHIVE}
  Created       ${created}
  From revision ${source_revision}
  Database      ${archived_db}
  Environment   ${archived_env}

  This host
  Database      ${current_db}
  Install path  ${INSTALL_DIR}

DETAILS

    if [ "${archived_db}" != "${current_db}" ]; then
        warn "The archive's database name (${archived_db}) differs from this host's (${current_db})."
        warn "That usually means this backup came from a different installation."
        if [ "${ASSUME_YES}" != "true" ]; then
            ask_yes_no "Restore it anyway?" n || die "Stopped at the operator's request."
        fi
    fi

    ok "Manifest accepted."
}

# Minimal field reader: the manifest is written by backup.sh, one flat string
# field per line, so this needs no JSON dependency on the host.
json_field() {
    sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$1" | head -n 1
}

# ---------------------------------------------------------------------------
# Confirmation
# ---------------------------------------------------------------------------

confirm_destruction() {
    if [ "${ASSUME_YES}" = "true" ]; then
        warn "Proceeding without interactive confirmation (--yes was given)."
        return 0
    fi

    interactive || die "This restore would replace the live database, and there is no terminal to confirm on.
Re-run with --yes if that is genuinely what you intend."

    cat >&2 <<WARNING

${C_RED}${C_BOLD}  This replaces the live database.${C_RESET}

  Everything written since this archive was taken will be gone: orders,
  wallet balances, ledger rows, invoices, provisioned servers and audit
  history. It cannot be undone except from another backup.

WARNING

    local typed
    printf 'Type exactly "%s" to continue: ' "${CONFIRM_PHRASE}" >&2
    IFS= read -r typed < /dev/tty

    # A deliberate sentence, not a keystroke: "y" is too easy to type by reflex.
    [ "${typed}" = "${CONFIRM_PHRASE}" ] || die "Confirmation did not match. Nothing has been changed."

    ok "Confirmed."
}

# ---------------------------------------------------------------------------
# Restore
# ---------------------------------------------------------------------------

# The current state is captured before it is replaced, so a restore from the
# wrong archive is itself recoverable.
take_safety_backup() {
    step "Backing up the current state before replacing it"

    if SAFETY_ARCHIVE="$("${INSTALL_DIR}/backup.sh" --quiet --reason pre-restore 2>/dev/null)"; then
        ok "Current state saved to ${SAFETY_ARCHIVE}"
        return 0
    fi

    SAFETY_ARCHIVE=""
    warn "Could not back up the current state — the database may already be unusable."
    if [ "${ASSUME_YES}" != "true" ]; then
        ask_yes_no "Continue without a safety backup?" n || die "Stopped at the operator's request."
    fi
}

# Restored into the running PostgreSQL with --clean --if-exists, so the
# existing objects are dropped and recreated inside the same volume. The volume
# itself is never removed: doing that is how a restore becomes an outage.
restore_database() {
    local user database

    user="$(env_get "${ENV_FILE}" DB_USERNAME)"
    database="$(env_get "${ENV_FILE}" DB_DATABASE)"

    # --exit-on-error so a partially restored database is a failure, not a
    # surprise discovered later by a customer.
    if ! compose exec -T \
            -e PGPASSWORD="$(env_get "${ENV_FILE}" DB_PASSWORD)" \
            postgres pg_restore \
                --username="${user}" \
                --dbname="${database}" \
                --clean \
                --if-exists \
                --no-owner \
                --no-privileges \
                --exit-on-error \
            < "${WORK_DIR}/database.dump"; then
        err "pg_restore failed. The database may be partially restored."
        [ -n "${SAFETY_ARCHIVE}" ] && err "The pre-restore state is preserved at: ${SAFETY_ARCHIVE}"
        die "Restore failed. Operator intervention is required."
    fi

    ok "Database restored."
}

# Off by default, and loudly explained when on: the archived APP_KEY decides
# what the restored encrypted columns mean.
restore_env() {
    step "Restoring the archived configuration"

    [ -f "${WORK_DIR}/env" ] || { warn "The archive contains no env file; keeping the current one."; return 0; }

    local backup_name
    backup_name="${ENV_FILE}.before-restore.$(date -u +%Y%m%dT%H%M%SZ)"
    cp -p "${ENV_FILE}" "${backup_name}"
    chmod 600 "${backup_name}"

    ( umask 077; cp "${WORK_DIR}/env" "${ENV_FILE}" )
    chmod 600 "${ENV_FILE}"

    ok "Configuration restored. The previous one is at ${backup_name}"
    log "  The archived APP_KEY is now in effect; it is what the restored encrypted columns were written with."
}

restore_storage() {
    [ -f "${WORK_DIR}/storage-app.tar.gz" ] || return 0

    step "Restoring stored application files"
    tar -xzf "${WORK_DIR}/storage-app.tar.gz" -C "${INSTALL_DIR}/storage"
    ok "Stored files restored."
}

final_report() {
    cat <<REPORT

${C_GREEN}${C_BOLD}Restore complete.${C_RESET}

  Restored from   ${ARCHIVE}
  Pre-restore     ${SAFETY_ARCHIVE:-none taken}
  Database        $(env_get "${ENV_FILE}" DB_DATABASE)
  Configuration   $(if [ "${RESTORE_ENV}" = "true" ]; then echo "replaced from the archive"; else echo "kept as it was"; fi)
  Health          verified through the local endpoint

${C_BOLD}Check before calling this done${C_RESET}
  1. Sign in to /admin and confirm recent orders and wallet balances look right.
  2. Run: docker compose run --rm app php artisan wallet:verify-integrity
  3. Confirm the Telegram webhook still points here: artisan telegram:webhook info
  4. Reconcile against providers before enabling sales, so nothing provisions twice.

  The decrypted copy of this archive has been removed from disk.

REPORT
}

main "$@"
