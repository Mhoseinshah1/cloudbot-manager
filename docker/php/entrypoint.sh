#!/bin/sh
#
# POSIX sh, not bash: the runtime image is Alpine-based and has no bash. CI
# still syntax-checks this with `bash -n`, and ShellCheck lints it in sh mode.
#
# Container entrypoint for the app, worker and scheduler containers.
#
# Two deliberate omissions:
#
#   1. No key generation. A container that generated its own APP_KEY on start
#      would produce a different key per container and per restart, making
#      every previously encrypted value unreadable. Keys are created once, by
#      the installer, and preserved across updates.
#
#   2. No migrations. Schema changes run as an explicit deployment step with
#      the workers stopped, so that two containers starting at once cannot
#      race each other through the migrator.
set -eu

fail() {
    echo "entrypoint: $1" >&2
    exit 1
}

# Fail fast rather than serving traffic with a broken encrypter.
[ -n "${APP_KEY:-}" ] || fail "APP_KEY is empty. Generate it once during install and preserve it across updates."

case "${APP_KEY}" in
    base64:*) ;;
    *) fail "APP_KEY is not in the expected base64: format." ;;
esac

# Warm the caches this container will serve from. Failure here is fatal:
# booting with a stale or partial cache hides configuration errors.
if [ "${CONTAINER_CACHE_CONFIG:-true}" = "true" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
fi

exec "$@"
