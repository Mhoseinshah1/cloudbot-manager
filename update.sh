#!/usr/bin/env bash
#
# CloudBot Manager updater.
#
#   cd /opt/cloudbot-manager && sudo ./update.sh
#
# What it will not do, in order of how much damage each would cause:
#
#   * never removes a volume, and never runs `docker compose down -v`;
#   * never discards operator changes to tracked files — it stops instead;
#   * never regenerates APP_KEY;
#   * never rolls a migration back on its own.
#
# Order: stop workers, back up, deploy source, migrate, recreate app, recreate
# the proxy, restart workers, verify. Workers stop before the schema changes so
# no job runs against a half-migrated database.

set -Eeuo pipefail

INSTALL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly INSTALL_DIR
readonly ENV_FILE="${INSTALL_DIR}/.env"
readonly LOCK_FILE="/var/lock/cloudbot-manager-deploy.lock"

# shellcheck source=scripts/lib/common.sh
. "${INSTALL_DIR}/scripts/lib/common.sh"
# shellcheck source=scripts/lib/stack.sh
. "${INSTALL_DIR}/scripts/lib/stack.sh"

BRANCH=""
SKIP_BACKUP=false
FAILED_STAGE="startup"
OLD_REVISION=""
NEW_REVISION=""
SOURCE_MOVED=false
MIGRATIONS_RAN=false
BACKUP_ARCHIVE=""

usage() {
    cat <<'USAGE'
Usage: sudo ./update.sh [--branch <name>] [--skip-backup] [--help]

  --branch <name>  Branch to deploy. Defaults to the currently tracked branch.
  --skip-backup    Do not take a backup first. Only for a host where backups
                   are already handled externally and very recently verified.
  --help           Show this message.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --branch) [ $# -ge 2 ] || die "--branch needs a value."; BRANCH="$2"; shift 2 ;;
        --skip-backup) SKIP_BACKUP=true; shift ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; die "Unknown argument: $1" ;;
    esac
done

umask 077

main() {
    require_root
    take_lock "${LOCK_FILE}"
    require_docker

    [ -f "${ENV_FILE}" ] || die "No .env found in ${INSTALL_DIR}. Run install.sh first."
    [ -d "${INSTALL_DIR}/.git" ] || die "${INSTALL_DIR} is not a git checkout; this updater deploys from git."

    arm_failure_diagnostics
    trap on_failure ERR

    FAILED_STAGE="working tree check"
    assert_clean_worktree

    FAILED_STAGE="source inspection"
    resolve_branch
    fetch_source

    OLD_REVISION="$(git -C "${INSTALL_DIR}" rev-parse HEAD)"
    NEW_REVISION="$(git -C "${INSTALL_DIR}" rev-parse "origin/${BRANCH}")"

    log "  current  ${OLD_REVISION}"
    log "  target   ${NEW_REVISION} (origin/${BRANCH})"

    if [ "${OLD_REVISION}" = "${NEW_REVISION}" ]; then
        ok "Already at the latest revision of ${BRANCH}."
        # Still verified: the source being current says nothing about whether
        # the containers are actually serving.
        verify_local_health || die "Source is current but the deployment is unhealthy."
        exit 0
    fi

    assert_fast_forward

    FAILED_STAGE="worker shutdown"
    stop_workers

    FAILED_STAGE="backup"
    take_backup

    FAILED_STAGE="source deployment"
    deploy_source

    FAILED_STAGE="image build"
    step "Rebuilding the application image"
    compose build

    FAILED_STAGE="migrations"
    run_migrations

    FAILED_STAGE="application restart"
    step "Recreating the application"
    compose up -d --force-recreate app
    wait_for_healthy app 180

    FAILED_STAGE="proxy restart"
    # The app container has a new address; the proxy is recreated so no request
    # lands on a stale upstream.
    recreate_proxy

    FAILED_STAGE="worker restart"
    start_workers

    FAILED_STAGE="health verification"
    verify_local_health 120

    FAILED_STAGE="done"
    trap - ERR

    step "Update complete"
    log "  ${OLD_REVISION} -> ${NEW_REVISION}"
    [ -n "${BACKUP_ARCHIVE}" ] && log "  backup: ${BACKUP_ARCHIVE}"
    ok "The deployment is healthy."
}

# ---------------------------------------------------------------------------
# Source safety
# ---------------------------------------------------------------------------

# An operator's local edit is a decision somebody made on purpose, possibly at
# three in the morning during an incident. Discarding it silently would be the
# single most destructive thing this script could do, so it stops instead —
# and prints paths only, because the contents may be exactly the secret the
# operator patched in.
assert_clean_worktree() {
    step "Checking the working tree"

    local changed
    changed="$(git -C "${INSTALL_DIR}" status --porcelain --untracked-files=no)"

    if [ -z "${changed}" ]; then
        ok "Working tree is clean."
        return 0
    fi

    err "Tracked files have local modifications. Refusing to update."
    printf '\n  Changed paths (contents deliberately not shown):\n' >&2
    printf '%s\n' "${changed}" | awk '{print "    " $0}' >&2

    cat >&2 <<'EXPLAIN'

  This updater will not overwrite your changes. Nothing has been touched.

  Save them first, then re-run:
      git -C . diff > /root/cloudbot-local-changes.patch   # then review it
      git -C . stash push -m "pre-update"                  # or stash them

  Only once you have decided they are disposable should you remove them, by
  hand, deliberately. This script will not run `git reset --hard`,
  `git checkout -- .` or `git clean -fd` for you.
EXPLAIN

    exit 1
}

resolve_branch() {
    if [ -z "${BRANCH}" ]; then
        BRANCH="$(git -C "${INSTALL_DIR}" rev-parse --abbrev-ref HEAD)"
        # A detached HEAD has no branch to follow; the operator must say which.
        [ "${BRANCH}" != "HEAD" ] || die "The checkout is in a detached HEAD state. Re-run with --branch <name>."
    fi

    valid_git_ref "${BRANCH}" || die "Refusing to use an unsafe branch name: ${BRANCH}"
    ok "Deploying branch ${BRANCH}."
}

fetch_source() {
    step "Fetching ${BRANCH}"

    local remote
    remote="$(git -C "${INSTALL_DIR}" remote get-url origin 2>/dev/null || true)"
    [ -n "${remote}" ] || die "No 'origin' remote is configured."
    log "  origin: ${remote}"

    # Only the one branch, and no tags: fetching everything from a remote that
    # has been tampered with gives it more to offer than was asked for.
    local attempt=1 delay=2
    while [ "${attempt}" -le 4 ]; do
        if git -C "${INSTALL_DIR}" fetch --no-tags origin "${BRANCH}"; then
            ok "Fetched origin/${BRANCH}."
            return 0
        fi
        warn "Fetch failed (attempt ${attempt}). Retrying in ${delay}s."
        sleep "${delay}"
        delay=$((delay * 2))
        attempt=$((attempt + 1))
    done

    die "Could not fetch origin/${BRANCH} after 4 attempts."
}

# A non-fast-forward means the remote branch was rewritten under a running
# deployment. That is not a routine update, and guessing which side is right
# is how an operator loses a hotfix.
assert_fast_forward() {
    if git -C "${INSTALL_DIR}" merge-base --is-ancestor "${OLD_REVISION}" "${NEW_REVISION}"; then
        ok "Update is a fast-forward."
        return 0
    fi

    err "origin/${BRANCH} is not a descendant of the deployed revision."
    cat >&2 <<EXPLAIN

  The branch has been rewritten, or this host is on a revision that is not on
  the branch any more. Deploying it would silently discard whatever is only in
  the running revision.

    deployed: ${OLD_REVISION}
    remote:   ${NEW_REVISION}

  Nothing has been changed. Decide deliberately which revision should be live,
  then check it out by hand and re-run.
EXPLAIN

    exit 1
}

deploy_source() {
    step "Deploying ${NEW_REVISION}"

    # Fast-forward only, already proven above. No reset, no clean.
    git -C "${INSTALL_DIR}" merge --ff-only "origin/${BRANCH}"
    SOURCE_MOVED=true

    ok "Source updated."
}

# ---------------------------------------------------------------------------
# Backup and migrations
# ---------------------------------------------------------------------------

take_backup() {
    if [ "${SKIP_BACKUP}" = "true" ]; then
        warn "Skipping backup at the operator's request."
        return 0
    fi

    step "Backing up before making changes"

    # Captured so the failure path and the final report can name it.
    local output
    output="$("${INSTALL_DIR}/backup.sh" --quiet --reason pre-update)" || die "Backup failed. Refusing to update without one."
    BACKUP_ARCHIVE="${output}"

    ok "Backup written: ${BACKUP_ARCHIVE}"
}

run_migrations() {
    step "Running migrations"

    if ! artisan migrate --force; then
        err "Migrations failed."
        return 1
    fi

    MIGRATIONS_RAN=true
    ok "Migrations applied."
}

# ---------------------------------------------------------------------------
# Failure and rollback
# ---------------------------------------------------------------------------

# Rolling the source back is safe only while the schema has not moved. Once a
# migration has run, the old code may not understand the new schema, and
# reversing a migration automatically is how a deploy turns into data loss.
on_failure() {
    local code=$?
    trap - ERR

    err "Update failed during: ${FAILED_STAGE}"

    if [ -n "${BACKUP_ARCHIVE}" ]; then
        log "  A backup taken before this update is preserved at: ${BACKUP_ARCHIVE}"
    fi

    if [ "${SOURCE_MOVED}" = "true" ] && [ "${MIGRATIONS_RAN}" = "false" ]; then
        attempt_source_rollback
    elif [ "${MIGRATIONS_RAN}" = "true" ]; then
        cat >&2 <<EXPLAIN

  Migrations have already been applied, so the source is NOT being rolled back.
  The previous revision may not understand the current schema, and reversing a
  migration automatically risks the data it has already rewritten.

  This needs a person. Start here:

    deployed revision: ${NEW_REVISION}
    previous revision: ${OLD_REVISION}
    backup:            ${BACKUP_ARCHIVE:-none taken}

  Operator intervention is required.
EXPLAIN
    else
        log "  The source was not changed; the previous revision is still deployed."
    fi

    diagnostics
    exit "${code}"
}

attempt_source_rollback() {
    step "Rolling the source back to ${OLD_REVISION}"

    if ! git -C "${INSTALL_DIR}" merge --ff-only "${OLD_REVISION}" 2>/dev/null; then
        # Moving backwards is not a fast-forward; this is the one place a
        # deliberate reset is correct, and only to a revision this script
        # itself recorded a moment ago.
        git -C "${INSTALL_DIR}" reset --hard "${OLD_REVISION}" >/dev/null 2>&1 || {
            err "Could not restore ${OLD_REVISION}. The checkout needs manual attention."
            return 1
        }
    fi

    step "Rebuilding and restarting on the previous revision"
    compose build >/dev/null 2>&1 || warn "Rebuild on the previous revision failed."
    compose up -d --force-recreate app >/dev/null 2>&1 || warn "Could not recreate the app container."
    wait_for_healthy app 120 || warn "The application is not healthy on the previous revision."
    recreate_proxy || warn "Could not recreate the proxy."
    start_workers || warn "Could not restart the workers."

    # Claimed only if it is actually true.
    if verify_local_health 60; then
        ok "Rolled back to ${OLD_REVISION} and the deployment is healthy."
    else
        err "Rolled the source back to ${OLD_REVISION}, but the deployment is still unhealthy."
        err "Operator intervention is required."
    fi
}

main "$@"
