#!/usr/bin/env bash
#
# Docker Compose operations, health gating and sanitized failure diagnostics.
#
# Two rules hold everywhere in this file:
#
#   1. No path here ever runs `docker compose down -v` or removes a named
#      volume. The database and Redis volumes are the production data.
#   2. Diagnostics print container state and log tails, never configuration.
#      A failed deploy is exactly when somebody copies the output into a chat.

if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    echo "stack.sh is a library and must be sourced, not executed." >&2
    exit 1
fi

# Every service that runs application PHP. Stopped before migrations so two
# processes cannot race each other through a schema change.
readonly WORKER_SERVICES=(telegram-worker provisioning-worker notification-worker scheduler)

# ---------------------------------------------------------------------------
# Docker
# ---------------------------------------------------------------------------

require_docker() {
    have docker || die "Docker is not installed. Install Docker Engine and re-run."
    docker compose version >/dev/null 2>&1 \
        || die "The Docker Compose plugin is missing. Install docker-compose-plugin and re-run."
    docker info >/dev/null 2>&1 \
        || die "Cannot talk to the Docker daemon. Is it running, and are you root?"
}

# Installs Docker Engine from Docker's own repository. Only ever called after
# the operator has agreed, and never on a host that already has Docker.
install_docker() {
    step "Installing Docker Engine"

    export DEBIAN_FRONTEND=noninteractive

    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg

    install -m 0755 -d /etc/apt/keyrings
    if [ ! -f /etc/apt/keyrings/docker.asc ]; then
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
        chmod a+r /etc/apt/keyrings/docker.asc
    fi

    local codename
    # shellcheck disable=SC1091
    codename="$(. /etc/os-release && printf '%s' "${VERSION_CODENAME}")"

    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu %s stable\n' \
        "$(dpkg --print-architecture)" "${codename}" > /etc/apt/sources.list.d/docker.list

    apt-get update -qq
    apt-get install -y -qq docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    systemctl enable --now docker

    ok "Docker Engine installed."
}

# ---------------------------------------------------------------------------
# Compose wrappers
#
# Always --env-file .env, so a service never inherits a value from the
# operator's shell that differs from what the containers will actually read.
# ---------------------------------------------------------------------------

compose() {
    docker compose --project-directory "${INSTALL_DIR}" --env-file "${ENV_FILE}" -f "${INSTALL_DIR}/compose.yaml" "$@"
}

compose_config_valid() {
    compose config >/dev/null 2>&1
}

# ---------------------------------------------------------------------------
# Health
# ---------------------------------------------------------------------------

# Waits for a named service to report healthy. Returns non-zero on timeout
# rather than dying, so the caller can produce diagnostics first.
wait_for_healthy() {
    local service="$1" timeout="${2:-180}" waited=0 id state

    while [ "${waited}" -lt "${timeout}" ]; do
        id="$(compose ps -q "${service}" 2>/dev/null || true)"

        if [ -n "${id}" ]; then
            state="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "${id}" 2>/dev/null || true)"
            case "${state}" in
                healthy|running) [ "${state}" = "healthy" ] && { ok "${service} is healthy."; return 0; } ;;
                exited|dead) err "${service} exited while starting."; return 1 ;;
            esac
        fi

        sleep 3
        waited=$((waited + 3))
    done

    err "${service} did not become healthy within ${timeout}s."
    return 1
}

wait_for_infrastructure() {
    local timeout="${1:-180}"

    wait_for_healthy postgres "${timeout}" || return 1
    wait_for_healthy redis "${timeout}" || return 1
}

# The only definition of "the deployment works": the application answers over
# HTTP through the Docker nginx, on the port the operator published.
#
# A running container is not a healthy deployment, which is why this checks the
# response body rather than the container state.
verify_local_health() {
    local timeout="${1:-120}"
    local bind port url waited=0 body
    bind="$(env_get "${ENV_FILE}" APP_BIND_IP || printf '127.0.0.1')"
    port="$(env_get "${ENV_FILE}" APP_PORT || printf '8080')"

    # A wildcard bind is not an address to connect to.
    [ "${bind}" = "0.0.0.0" ] && bind="127.0.0.1"
    url="http://${bind}:${port}/health"

    while [ "${waited}" -lt "${timeout}" ]; do
        body="$(curl --fail --silent --show-error --max-time 10 "${url}" 2>/dev/null || true)"
        if printf '%s' "${body}" | grep -q '"status":"ok"'; then
            ok "Application health endpoint is green at ${url}."
            return 0
        fi
        sleep 3
        waited=$((waited + 3))
    done

    err "Health endpoint did not return status ok at ${url} within ${timeout}s."
    return 1
}

# Public HTTPS, only checked once TLS has actually been set up. Deliberately
# does not pass -k: an installer that accepts an invalid certificate teaches
# the operator that the warning does not matter.
verify_public_health() {
    local domain="$1" timeout="${2:-60}" waited=0 body

    while [ "${waited}" -lt "${timeout}" ]; do
        body="$(curl --fail --silent --show-error --max-time 10 "https://${domain}/health" 2>/dev/null || true)"
        if printf '%s' "${body}" | grep -q '"status":"ok"'; then
            ok "Public HTTPS health endpoint is green at https://${domain}/health."
            return 0
        fi
        sleep 3
        waited=$((waited + 3))
    done

    err "https://${domain}/health did not answer with status ok within ${timeout}s."
    return 1
}

# ---------------------------------------------------------------------------
# Application containers
# ---------------------------------------------------------------------------

# Runs an artisan command in a throwaway container on the production image and
# environment. Used for migrations and bootstrap, never for serving traffic.
artisan() {
    compose run --rm --no-deps -T app php artisan "$@"
}

# Same, but attached to the terminal so Laravel Prompts can read hidden input.
artisan_tty() {
    compose run --rm --no-deps app php artisan "$@"
}

start_infrastructure() {
    step "Starting PostgreSQL and Redis"
    compose up -d postgres redis
    wait_for_infrastructure 180 || return 1
}

start_application() {
    step "Starting the application, workers and scheduler"
    compose up -d
    wait_for_healthy app 180 || return 1
    # nginx is recreated deliberately: see recreate_proxy.
    recreate_proxy
}

# The stale-upstream fix.
#
# Recreating the app container gives it a new address on the Docker network.
# nginx is configured to re-resolve through Docker's embedded DNS, which
# handles that, but a proxy started before the app existed can still hold a
# failed resolution. Recreating nginx after the app is healthy costs a second
# and removes the whole class of "everything is running but the site 502s".
recreate_proxy() {
    step "Recreating the Docker nginx proxy against the current app container"
    compose up -d --force-recreate nginx
    wait_for_healthy nginx 90 || return 1
}

stop_workers() {
    step "Stopping workers and the scheduler"
    # Stopped, not removed: their containers and restart policy stay defined.
    compose stop "${WORKER_SERVICES[@]}"
    ok "Workers stopped."
}

start_workers() {
    step "Starting workers and the scheduler"
    compose up -d "${WORKER_SERVICES[@]}"
    ok "Workers started."
}

# ---------------------------------------------------------------------------
# Diagnostics
#
# Printed when something fails. Everything here is either container state or a
# log tail. No .env, no APP_KEY, no database password, no provider token, no
# Telegram token, and no root passwords: a failed deploy is precisely when
# output gets pasted somewhere public.
# ---------------------------------------------------------------------------

diagnostics() {
    local bind port

    printf '\n%s======== deployment diagnostics ========%s\n' "${C_BOLD}" "${C_RESET}" >&2

    printf '\n--- container state ---\n' >&2
    compose ps 2>&1 | sed 's/^/  /' >&2 || true

    printf '\n--- application health endpoint ---\n' >&2
    bind="$(env_get "${ENV_FILE}" APP_BIND_IP 2>/dev/null || printf '127.0.0.1')"
    port="$(env_get "${ENV_FILE}" APP_PORT 2>/dev/null || printf '8080')"
    [ "${bind}" = "0.0.0.0" ] && bind="127.0.0.1"
    printf '  HTTP status: %s\n' \
        "$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 10 "http://${bind}:${port}/health" 2>/dev/null || printf 'unreachable')" >&2

    printf '\n--- postgres ---\n' >&2
    compose exec -T postgres pg_isready 2>&1 | sed 's/^/  /' >&2 || printf '  unreachable\n' >&2

    printf '\n--- redis ---\n' >&2
    compose exec -T redis redis-cli ping 2>&1 | sed 's/^/  /' >&2 || printf '  unreachable\n' >&2

    local service
    for service in app nginx "${WORKER_SERVICES[@]}"; do
        printf '\n--- %s (last 40 lines) ---\n' "${service}" >&2
        compose logs --tail=40 --no-color "${service}" 2>&1 | scrub_secrets | sed 's/^/  /' >&2 || true
    done

    printf '\n%s=======================================%s\n' "${C_BOLD}" "${C_RESET}" >&2
    printf 'Configuration values are deliberately omitted from this output.\n' >&2
}

# Last-resort filter over log output.
#
# The application already scrubs its own structured logs. This is the belt to
# that pair of braces: a stack trace, a third-party library or a crash before
# the logger is configured can still print a token, and this output is what
# gets pasted into a support thread.
scrub_secrets() {
    sed -E \
        -e 's/(bot)[0-9]{6,}:[A-Za-z0-9_-]{20,}/\1<redacted-telegram-token>/g' \
        -e 's/([0-9]{6,}:[A-Za-z0-9_-]{30,})/<redacted-telegram-token>/g' \
        -e 's/(base64:)[A-Za-z0-9+\/=]{20,}/\1<redacted-app-key>/g' \
        -e 's/([Bb]earer )[A-Za-z0-9._-]{12,}/\1<redacted>/g' \
        -e 's/((PGPASSWORD|DB_PASSWORD|REDIS_PASSWORD|APP_KEY|TELEGRAM_BOT_TOKEN|TELEGRAM_WEBHOOK_SECRET|HETZNER_API_TOKEN|BACKUP_PASSPHRASE)[=:][[:space:]]*)[^[:space:]"]+/\1<redacted>/g' \
        -e 's#(postgres(ql)?://[^:]+:)[^@]+@#\1<redacted>@#g'
}

# Installs a trap that prints diagnostics when the script exits non-zero.
# Callers set FAILED_STAGE as they go, so the report names where it stopped.
arm_failure_diagnostics() {
    trap 'deployment_failed $?' ERR
}

deployment_failed() {
    local code="$1"
    err "Failed during: ${FAILED_STAGE:-unknown stage} (exit ${code})"
    diagnostics
    exit "${code}"
}
