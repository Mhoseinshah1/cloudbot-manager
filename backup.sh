#!/usr/bin/env bash
#
# CloudBot Manager backup.
#
#   sudo ./backup.sh                      # encrypted archive into ./backups
#   sudo ./backup.sh --quiet              # prints only the archive path
#   sudo ./backup.sh --output-dir /mnt/x  # somewhere else
#
# Produces one encrypted, timestamped, mode-600 archive containing everything
# needed to rebuild this installation: the database, the configuration and the
# application's own stored files. It contains nothing that can be reinstalled —
# no vendor/, no caches, no build output.
#
# Encryption is not optional. The archive holds a full database dump, and that
# database holds encrypted provider tokens and server root credentials whose
# key lives in the .env this archive also carries.
#
# The key is kept OUTSIDE the archive, in a separate file, because a key stored
# inside the thing it encrypts is not a key. Copy that file somewhere else — a
# backup you cannot decrypt is not a backup.
#
# What that file holds depends on the tool: an age identity for age, a
# passphrase for gpg. Both are passed to the tool as a FILE PATH, never on the
# command line and never through a shell variable.
#
# Every rm in this file guards its variables with :? so that an unset or empty
# path aborts instead of expanding to something catastrophic.

set -Eeuo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly INSTALL_DIR
readonly ENV_FILE="${INSTALL_DIR}/.env"

# shellcheck source=scripts/lib/common.sh
. "${INSTALL_DIR}/scripts/lib/common.sh"
# shellcheck source=scripts/lib/stack.sh
. "${INSTALL_DIR}/scripts/lib/stack.sh"

OUTPUT_DIR="${CLOUDBOT_BACKUP_DIR:-${INSTALL_DIR}/backups}"
PASSPHRASE_FILE="${CLOUDBOT_BACKUP_PASSPHRASE_FILE:-/etc/cloudbot-manager/backup.passphrase}"
QUIET=false
REASON="manual"
KEEP_DAILY="${CLOUDBOT_BACKUP_KEEP_DAILY:-7}"
KEEP_WEEKLY="${CLOUDBOT_BACKUP_KEEP_WEEKLY:-4}"
WORK_DIR=""

usage() {
    cat <<'USAGE'
Usage: sudo ./backup.sh [options]

  --output-dir <dir>    Where to write the archive. Default ./backups
  --passphrase-file <f> Key file (age identity or gpg passphrase).
                        Default /etc/cloudbot-manager/backup.passphrase
  --reason <text>       Recorded in the manifest. Default "manual"
  --quiet               Print only the resulting archive path
  --help                Show this message

Environment:
  CLOUDBOT_BACKUP_DIR, CLOUDBOT_BACKUP_PASSPHRASE_FILE,
  CLOUDBOT_BACKUP_KEEP_DAILY (default 7), CLOUDBOT_BACKUP_KEEP_WEEKLY (default 4)
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --output-dir) [ $# -ge 2 ] || die "--output-dir needs a value."; OUTPUT_DIR="$2"; shift 2 ;;
        --passphrase-file) [ $# -ge 2 ] || die "--passphrase-file needs a value."; PASSPHRASE_FILE="$2"; shift 2 ;;
        --reason) [ $# -ge 2 ] || die "--reason needs a value."; REASON="$2"; shift 2 ;;
        --quiet) QUIET=true; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; die "Unknown argument: $1" ;;
    esac
done

# Everything this script writes contains production data.
umask 077

# Plaintext intermediates must not outlive the run, whatever happens.
cleanup() {
    [ -n "${WORK_DIR}" ] && [ -d "${WORK_DIR}" ] && rm -rf -- "${WORK_DIR:?}"
    return 0
}
trap cleanup EXIT INT TERM

say() { [ "${QUIET}" = "true" ] || "$@"; }

main() {
    require_root
    require_docker
    [ -f "${ENV_FILE}" ] || die "No .env found in ${INSTALL_DIR}."

    case "${OUTPUT_DIR}" in
        /*) ;;
        *) OUTPUT_DIR="${INSTALL_DIR}/${OUTPUT_DIR#./}" ;;
    esac
    [ -n "${OUTPUT_DIR}" ] || die "The output directory cannot be empty."

    mkdir -p "${OUTPUT_DIR}"
    chmod 700 "${OUTPUT_DIR}"

    local encryptor key_file stamp archive
    encryptor="$(select_encryptor)"
    key_file="$(ensure_key_file "${encryptor}")"

    stamp="$(date -u +%Y%m%dT%H%M%SZ)"
    archive="${OUTPUT_DIR}/cloudbot-${stamp}.tar.gz.${encryptor}"

    WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/cloudbot-backup.XXXXXX")"
    chmod 700 "${WORK_DIR}"

    say step "Backing up the database"
    dump_database "${WORK_DIR}/database.dump"

    say step "Collecting configuration and stored files"
    collect_files "${WORK_DIR}"
    write_manifest "${WORK_DIR}" "${stamp}"

    say step "Building the encrypted archive"
    build_archive "${WORK_DIR}" "${archive}" "${encryptor}" "${key_file}"

    say step "Verifying the archive"
    verify_archive "${archive}" "${encryptor}" "${key_file}"

    prune_old_backups

    if [ "${QUIET}" = "true" ]; then
        printf '%s\n' "${archive}"
    else
        report "${archive}"
    fi
}

# ---------------------------------------------------------------------------
# Encryption
# ---------------------------------------------------------------------------

# age when present, gpg otherwise. Both are established, both do authenticated
# encryption, and both take the passphrase from a file descriptor rather than
# an argument — so it never appears in the process list.
select_encryptor() {
    if have age; then printf 'age'
    elif have gpg; then printf 'gpg'
    else die "Neither age nor gpg is installed. Install one: apt-get install -y age"
    fi
}

# The key lives outside the archive, in its own root-only file, and is handed to
# the encryptor as a path. If it does not exist, one is generated once and kept.
#
# age has no way to take a passphrase non-interactively — it prompts on the
# terminal — so its key file is an age identity, which `--identity` reads
# directly. gpg takes a passphrase file. Either way nothing secret is ever an
# argument or a shell variable.
ensure_key_file() {
    local encryptor="$1" dir
    dir="$(dirname "${PASSPHRASE_FILE}")"

    if [ -r "${PASSPHRASE_FILE}" ]; then
        [ -s "${PASSPHRASE_FILE}" ] || die "${PASSPHRASE_FILE} is empty. Restore the real key, or remove the file to generate a new one — knowing that existing archives will then be unreadable."
        assert_key_matches_encryptor "${encryptor}"
        printf '%s' "${PASSPHRASE_FILE}"
        return 0
    fi

    mkdir -p "${dir}"
    chmod 700 "${dir}"

    case "${encryptor}" in
        age)
            ( umask 077; age-keygen -o "${PASSPHRASE_FILE}" >/dev/null 2>&1 ) \
                || die "Could not generate an age identity at ${PASSPHRASE_FILE}."
            ;;
        gpg)
            ( umask 077; printf '%s' "$(random_secret 32)" > "${PASSPHRASE_FILE}" )
            ;;
    esac

    chmod 600 "${PASSPHRASE_FILE}"

    # To stderr, every line of it: this function's stdout is the key path the
    # caller captures, so anything else written there becomes part of it.
    say warn "Generated a new backup key at ${PASSPHRASE_FILE}."
    say warn "  Copy that file somewhere off this host. Archives encrypted with it"
    say warn "  cannot be decrypted without it, and it is deliberately not stored"
    say warn "  inside them."

    printf '%s' "${PASSPHRASE_FILE}"
}

# An age identity handed to gpg, or a passphrase handed to age, fails in ways
# that are easy to misread as a corrupt archive. Say which it is instead.
assert_key_matches_encryptor() {
    local encryptor="$1"

    if [ "${encryptor}" = "age" ] && ! grep -q 'AGE-SECRET-KEY-' "${PASSPHRASE_FILE}"; then
        die "${PASSPHRASE_FILE} does not hold an age identity, but age is the encryptor in use.
Point --passphrase-file at the right key, or install gpg if that file is a passphrase."
    fi

    if [ "${encryptor}" = "gpg" ] && grep -q 'AGE-SECRET-KEY-' "${PASSPHRASE_FILE}"; then
        die "${PASSPHRASE_FILE} holds an age identity, but gpg is the encryptor in use.
Install age so the existing archives stay readable."
    fi
}

# ---------------------------------------------------------------------------
# Contents
# ---------------------------------------------------------------------------

# pg_dump in custom format: compressed, and restorable selectively by pg_restore.
#
# The password goes in through the container's environment, never as an
# argument. `set -o pipefail` is on, so a failure inside the container
# propagates rather than producing a truncated dump that looks successful.
dump_database() {
    local target="$1" user database

    user="$(env_get "${ENV_FILE}" DB_USERNAME)"
    database="$(env_get "${ENV_FILE}" DB_DATABASE)"

    compose exec -T \
        -e PGPASSWORD="$(env_get "${ENV_FILE}" DB_PASSWORD)" \
        postgres pg_dump \
            --username="${user}" \
            --dbname="${database}" \
            --format=custom \
            --compress=6 \
            --no-owner \
            --no-privileges \
        > "${target}"

    [ -s "${target}" ] || die "The database dump is empty. Refusing to write a backup that restores nothing."

    # A custom-format dump starts with the magic string PGDMP. Checking it here
    # turns "the dump was actually an error message" into a failed backup
    # rather than a surprise during a restore.
    head -c 5 "${target}" | grep -q 'PGDMP' \
        || die "The database dump is not a valid PostgreSQL custom-format archive."

    say ok "Database dumped ($(du -h "${target}" | cut -f1))."
}

# Only what cannot be reinstalled. vendor/, caches and build output are all
# reproducible from the repository and would multiply the archive size for
# nothing.
collect_files() {
    local work="$1"

    # The configuration, including APP_KEY. This is why the archive must be
    # encrypted: without this file the database's encrypted columns are lost,
    # and with it in the clear they are readable by anyone holding the archive.
    cp -p "${ENV_FILE}" "${work}/env"

    # Files the application itself stores at runtime. Logs are excluded: they
    # are rotated, reproducible noise, and not needed to restore service.
    if [ -d "${INSTALL_DIR}/storage/app" ]; then
        tar -czf "${work}/storage-app.tar.gz" -C "${INSTALL_DIR}/storage" app
    fi

    say ok "Configuration and stored files collected."
}

write_manifest() {
    local work="$1" stamp="$2" revision="unknown"

    if [ -d "${INSTALL_DIR}/.git" ]; then
        revision="$(git -C "${INSTALL_DIR}" rev-parse HEAD 2>/dev/null || printf 'unknown')"
    fi

    # Identifies the installation so restore.sh can refuse an archive that
    # belongs somewhere else. No secrets: names and revisions only.
    cat > "${work}/manifest.json" <<JSON
{
  "format": 1,
  "application": "cloudbot-manager",
  "created_at": "${stamp}",
  "reason": "${REASON}",
  "source_revision": "${revision}",
  "install_dir": "${INSTALL_DIR}",
  "database": "$(env_get "${ENV_FILE}" DB_DATABASE)",
  "app_env": "$(env_get "${ENV_FILE}" APP_ENV)",
  "contents": ["manifest.json", "database.dump", "env", "storage-app.tar.gz"]
}
JSON
}

# ---------------------------------------------------------------------------
# Archive
# ---------------------------------------------------------------------------

# Written to a temporary name and moved into place only after encryption
# succeeds, so an interrupted run cannot leave something that looks like a
# usable backup.
build_archive() {
    local work="$1" archive="$2" encryptor="$3" key_file="$4"
    local partial="${archive}.partial"

    # A checksum of the plaintext contents, carried inside the archive, so a
    # restore can prove the payload survived the round trip.
    ( cd "${work}" && sha256sum -- * > SHA256SUMS )

    # The key is read by the tool from its own file. Nothing secret is an
    # argument, so nothing secret is visible in the process list.
    case "${encryptor}" in
        age)
            tar -czf - -C "${work}" . \
                | age --encrypt --identity "${key_file}" --output "${partial}"
            ;;
        gpg)
            tar -czf - -C "${work}" . \
                | gpg --batch --yes --quiet \
                      --symmetric --cipher-algo AES256 \
                      --passphrase-file "${key_file}" \
                      --output "${partial}"
            ;;
    esac

    [ -s "${partial}" ] || die "Encryption produced an empty archive."

    chmod 600 "${partial}"
    mv "${partial}" "${archive}"
    chmod 600 "${archive}"
}

# An unverified backup is a guess. This decrypts the archive that was just
# written and checks the payload, rather than trusting that tar and the
# encryptor both did what they were asked.
verify_archive() {
    local archive="$1" encryptor="$2" key_file="$3" check_dir required
    check_dir="$(mktemp -d "${TMPDIR:-/tmp}/cloudbot-verify.XXXXXX")"
    chmod 700 "${check_dir}"

    # Guarded so a failure to create the directory cannot widen the cleanup.
    verify_cleanup() { rm -rf -- "${check_dir:?}"; }

    decrypt_stream "${archive}" "${encryptor}" "${key_file}" | tar -xzf - -C "${check_dir}" \
        || { verify_cleanup; die "The archive could not be decrypted and unpacked."; }

    local missing=()
    for required in manifest.json database.dump env SHA256SUMS; do
        [ -f "${check_dir}/${required}" ] || missing+=("${required}")
    done

    if [ "${#missing[@]}" -gt 0 ]; then
        verify_cleanup
        die "The archive is missing: ${missing[*]}"
    fi

    ( cd "${check_dir}" && sha256sum --quiet --check SHA256SUMS ) \
        || { verify_cleanup; die "Checksum verification failed: the archive contents do not match what was written."; }

    head -c 5 "${check_dir}/database.dump" | grep -q 'PGDMP' \
        || { verify_cleanup; die "The database dump inside the archive is not a valid PostgreSQL archive."; }

    verify_cleanup
    say ok "Archive decrypts, unpacks and matches its checksums."
}

decrypt_stream() {
    local archive="$1" encryptor="$2" key_file="$3"
    case "${encryptor}" in
        age) age --decrypt --identity "${key_file}" "${archive}" ;;
        gpg) gpg --batch --quiet --decrypt --passphrase-file "${key_file}" "${archive}" ;;
    esac
}

# ---------------------------------------------------------------------------
# Retention
# ---------------------------------------------------------------------------

# Keeps the most recent dailies, then one archive per ISO week beyond that.
# Only ever considers files this script produced, matched by name, so nothing
# else in the directory can be removed by a misconfigured output path.
prune_old_backups() {
    local -a archives=() keep=()
    local -A weeks=()
    local file candidate stamp week index=0 removed=0 kept

    while IFS= read -r file; do
        [ -n "${file}" ] && archives+=("${file}")
    done < <(find "${OUTPUT_DIR}" -maxdepth 1 -type f -name 'cloudbot-*.tar.gz.*' -printf '%f\n' 2>/dev/null | sort -r)

    [ "${#archives[@]}" -gt 0 ] || return 0

    for file in "${archives[@]}"; do
        if [ "${index}" -lt "${KEEP_DAILY}" ]; then
            keep+=("${file}")
            index=$((index + 1))
            continue
        fi

        stamp="${file#cloudbot-}"
        stamp="${stamp%%.*}"
        week="$(date -u -d "${stamp:0:4}-${stamp:4:2}-${stamp:6:2}" +%G-%V 2>/dev/null || printf '')"

        if [ -n "${week}" ] && [ -z "${weeks[${week}]:-}" ] && [ "${#weeks[@]}" -lt "${KEEP_WEEKLY}" ]; then
            weeks["${week}"]=1
            keep+=("${file}")
        fi
    done

    for file in "${archives[@]}"; do
        kept=false
        for candidate in "${keep[@]}"; do
            [ "${file}" = "${candidate}" ] && { kept=true; break; }
        done
        if [ "${kept}" = "false" ]; then
            # Both halves guarded: an empty either side aborts rather than
            # expanding into a path that removes far more than one archive.
            rm -f -- "${OUTPUT_DIR:?}/${file:?}"
            removed=$((removed + 1))
        fi
    done

    [ "${removed}" -gt 0 ] && say ok "Pruned ${removed} old archive(s); keeping ${KEEP_DAILY} daily and ${KEEP_WEEKLY} weekly."
    return 0
}

report() {
    local archive="$1"

    cat <<REPORT

${C_GREEN}${C_BOLD}Backup complete.${C_RESET}

  Archive      ${archive}
  Size         $(du -h "${archive}" | cut -f1)
  Permissions  $(stat -c '%a' "${archive}")
  Key file     ${PASSPHRASE_FILE}  (kept outside the archive, on purpose)
  Retention    ${KEEP_DAILY} daily, ${KEEP_WEEKLY} weekly

${C_BOLD}This backup is not safe yet.${C_RESET}
  A backup on the same host as the data is not a backup. Copy the archive off
  this machine, and keep the key file somewhere the archive is not — for
  example, from another host:

      rsync -a --chmod=600 root@this-host:${archive} /your/offsite/path/

  It is not a working backup until a restore has been tested from it.

REPORT
}

main "$@"
