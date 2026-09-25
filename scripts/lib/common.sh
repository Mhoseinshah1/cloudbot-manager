#!/usr/bin/env bash
#
# Shared helpers for install.sh, update.sh, backup.sh and restore.sh.
#
# Sourced, never executed. Everything here is deliberately conservative about
# two things: secrets must not reach a log, a process list or shell history,
# and nothing may destroy operator data. Functions that could do either say so
# in their own comment.

# Refuse to be run directly: the functions below assume the caller has already
# set its own error handling and INSTALL_DIR.
if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    echo "common.sh is a library and must be sourced, not executed." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# Output
# ---------------------------------------------------------------------------

# Colour only when stdout is a terminal, so redirected output stays readable.
if [ -t 1 ]; then
    readonly C_RESET=$'\033[0m'
    readonly C_BOLD=$'\033[1m'
    readonly C_RED=$'\033[31m'
    readonly C_GREEN=$'\033[32m'
    readonly C_YELLOW=$'\033[33m'
    readonly C_BLUE=$'\033[34m'
else
    readonly C_RESET='' C_BOLD='' C_RED='' C_GREEN='' C_YELLOW='' C_BLUE=''
fi

log()     { printf '%s\n' "$*"; }
step()    { printf '\n%s==>%s %s%s%s\n' "${C_BLUE}" "${C_RESET}" "${C_BOLD}" "$*" "${C_RESET}"; }
ok()      { printf '%s  ok%s %s\n' "${C_GREEN}" "${C_RESET}" "$*"; }
warn()    { printf '%swarn%s %s\n' "${C_YELLOW}" "${C_RESET}" "$*" >&2; }
err()     { printf '%sfail%s %s\n' "${C_RED}" "${C_RESET}" "$*" >&2; }

# Abort with a message. Callers rely on this never returning.
die() {
    err "$*"
    exit 1
}

# ---------------------------------------------------------------------------
# Environment and privileges
# ---------------------------------------------------------------------------

require_root() {
    if [ "$(id -u)" -ne 0 ]; then
        die "This script must run as root. Re-run with sudo."
    fi
}

# Ubuntu 22.04 and 24.04 are what this release is tested against. Anything else
# is reported rather than silently attempted: a half-supported host produces a
# broken install that looks finished.
require_supported_os() {
    local id version allow
    allow="${CLOUDBOT_ALLOW_UNSUPPORTED_OS:-false}"

    [ -r /etc/os-release ] || die "Cannot read /etc/os-release; this does not look like a supported host."

    # shellcheck disable=SC1091
    id="$(. /etc/os-release && printf '%s' "${ID:-}")"
    # shellcheck disable=SC1091
    version="$(. /etc/os-release && printf '%s' "${VERSION_ID:-}")"

    if [ "${id}" = "ubuntu" ] && { [ "${version}" = "22.04" ] || [ "${version}" = "24.04" ]; }; then
        ok "Host is Ubuntu ${version}."
        return 0
    fi

    if [ "${allow}" = "true" ]; then
        warn "Unsupported host (${id} ${version}); continuing because CLOUDBOT_ALLOW_UNSUPPORTED_OS=true."
        return 0
    fi

    die "Unsupported host: ${id} ${version}. This release supports Ubuntu 22.04 and 24.04.
Set CLOUDBOT_ALLOW_UNSUPPORTED_OS=true to override, and expect to fix things by hand."
}

have() { command -v "$1" >/dev/null 2>&1; }

# ---------------------------------------------------------------------------
# Validation
#
# Every operator-supplied value that reaches a config file, a URL, a shell
# command or nginx passes through one of these first.
# ---------------------------------------------------------------------------

valid_domain() {
    local domain="$1"
    [ -n "${domain}" ] || return 1
    [ "${#domain}" -le 253 ] || return 1
    [[ "${domain}" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$ ]]
}

valid_port() {
    local port="$1"
    [[ "${port}" =~ ^[0-9]{1,5}$ ]] || return 1
    [ "${port}" -ge 1 ] && [ "${port}" -le 65535 ]
}

valid_ipv4() {
    local ip="$1" octet
    [[ "${ip}" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
    for octet in ${ip//./ }; do
        [ "${octet}" -le 255 ] || return 1
    done
    return 0
}

valid_email() {
    [[ "$1" =~ ^[^[:space:]@]+@[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$ ]]
}

# Identifiers that become PostgreSQL role and database names. Restricted to
# what is safe unquoted, rather than trying to escape arbitrary input.
valid_identifier() {
    [[ "$1" =~ ^[a-z_][a-z0-9_]{0,62}$ ]]
}

# A git branch or tag, restricted enough that it cannot be read as an option or
# a path traversal when handed to git.
valid_git_ref() {
    local ref="$1"
    [ -n "${ref}" ] || return 1
    case "${ref}" in
        -*|*..*|*' '*|*'~'*|*'^'*|*':'*|*'?'*|*'*'*|*'['*|*\\*) return 1 ;;
    esac
    [[ "${ref}" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ ]]
}

valid_absolute_path() {
    case "$1" in
        /*) [[ "$1" != *..* ]] ;;
        *) return 1 ;;
    esac
}

# A Telegram chat id: an integer, optionally negative for groups and channels.
valid_chat_id() {
    [[ "$1" =~ ^-?[0-9]{1,20}$ ]]
}

valid_https_url() {
    [[ "$1" =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/%-]*)?$ ]]
}

# ---------------------------------------------------------------------------
# Secrets
#
# Cryptographically secure only. $RANDOM, timestamps and bare UUIDs are all
# predictable enough to be worth guessing, and a predictable webhook secret or
# database password is the whole security boundary.
# ---------------------------------------------------------------------------

# A URL- and shell-safe random string. Default 32 bytes of entropy.
random_secret() {
    local bytes="${1:-32}"
    if have openssl; then
        openssl rand -base64 "$((bytes * 2))" | tr -dc 'A-Za-z0-9' | head -c "$((bytes * 2))"
    else
        # /dev/urandom directly, so a host without openssl is still safe.
        LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c "$((bytes * 2))"
    fi
}

# Reads a secret without echoing it and without it ever becoming an argument,
# so it cannot appear in the process list or in shell history.
#   read_secret VARNAME "Prompt"
read_secret() {
    local __name="$1" __prompt="$2" __first __second
    while :; do
        printf '%s: ' "${__prompt}" >&2
        IFS= read -r -s __first < /dev/tty
        printf '\n' >&2
        printf 'Confirm %s: ' "${__prompt}" >&2
        IFS= read -r -s __second < /dev/tty
        printf '\n' >&2

        if [ -z "${__first}" ]; then
            warn "Empty value. Try again."
            continue
        fi
        if [ "${__first}" != "${__second}" ]; then
            warn "The values did not match. Try again."
            continue
        fi
        printf -v "${__name}" '%s' "${__first}"
        return 0
    done
}

# Reads a secret once, no confirmation, for values the operator is pasting.
read_secret_once() {
    local __name="$1" __prompt="$2" __value
    printf '%s: ' "${__prompt}" >&2
    IFS= read -r -s __value < /dev/tty
    printf '\n' >&2
    printf -v "${__name}" '%s' "${__value}"
}

ask() {
    local __name="$1" __prompt="$2" __default="${3:-}" __value
    if [ -n "${__default}" ]; then
        printf '%s [%s]: ' "${__prompt}" "${__default}" >&2
    else
        printf '%s: ' "${__prompt}" >&2
    fi
    IFS= read -r __value < /dev/tty
    [ -n "${__value}" ] || __value="${__default}"
    printf -v "${__name}" '%s' "${__value}"
}

ask_yes_no() {
    local prompt="$1" default="${2:-n}" answer
    while :; do
        if [ "${default}" = "y" ]; then
            printf '%s [Y/n]: ' "${prompt}" >&2
        else
            printf '%s [y/N]: ' "${prompt}" >&2
        fi
        IFS= read -r answer < /dev/tty
        [ -n "${answer}" ] || answer="${default}"
        case "${answer}" in
            [Yy]|[Yy][Ee][Ss]) return 0 ;;
            [Nn]|[Nn][Oo]) return 1 ;;
            *) warn "Answer y or n." ;;
        esac
    done
}

interactive() { [ -t 0 ] && [ -c /dev/tty ]; }

# ---------------------------------------------------------------------------
# .env handling
#
# Read and write single keys without rewriting the file wholesale, so operator
# comments and any key this installer does not know about survive untouched.
# ---------------------------------------------------------------------------

env_get() {
    local file="$1" key="$2" line
    [ -r "${file}" ] || return 1
    line="$(grep -E "^${key}=" "${file}" | tail -n 1 || true)"
    [ -n "${line}" ] || return 1
    line="${line#*=}"
    # Strip one layer of surrounding quotes, as Laravel's dotenv does.
    line="${line%\"}"; line="${line#\"}"
    printf '%s' "${line}"
}

# Sets a key, quoting the value so spaces and '#' cannot corrupt the file.
# Creates the file with mode 600 if it does not exist.
env_set() {
    local file="$1" key="$2" value="$3" tmp
    ( umask 077; [ -f "${file}" ] || : > "${file}" )

    tmp="$(mktemp "${file}.XXXXXX")"
    chmod 600 "${tmp}"

    # awk rather than sed: the value may contain characters sed would treat as
    # part of its replacement syntax.
    KEY="${key}" VALUE="${value}" awk '
        BEGIN { key = ENVIRON["KEY"]; value = ENVIRON["VALUE"]; written = 0 }
        $0 ~ "^" key "=" {
            if (!written) { printf "%s=\"%s\"\n", key, value; written = 1 }
            next
        }
        { print }
        END { if (!written) printf "%s=\"%s\"\n", key, value }
    ' "${file}" > "${tmp}"

    mv "${tmp}" "${file}"
    chmod 600 "${file}"
}

# True when the key is present and non-empty.
env_has() {
    local value
    value="$(env_get "$1" "$2" 2>/dev/null || true)"
    [ -n "${value}" ]
}

# ---------------------------------------------------------------------------
# Locking
# ---------------------------------------------------------------------------

# Takes an exclusive lock for the lifetime of the calling script. Two installs
# or two updates running at once would race each other through the migrator.
take_lock() {
    local lock_file="$1"
    exec 9>"${lock_file}" || die "Cannot open lock file ${lock_file}."
    if ! flock -n 9; then
        die "Another deployment operation is already running (lock: ${lock_file})."
    fi
}
