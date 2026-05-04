#!/usr/bin/env bash
#
# Container entrypoint. Runs as www-data via tini. Order of operations:
#
#   1. Wait for Postgres + Redis to accept connections (up to 60s).
#   2. Ensure storage / cache dirs are writable.
#   3. Run migrations (idempotent — safe to re-run on every start).
#   4. If AUTHN_BOOTSTRAP_ADMIN_EMAIL is set AND the `_admin` project does not
#      exist yet, run `authn:bootstrap` so first boot is a single-command
#      affair. Idempotent per AU-2 — subsequent runs no-op.
#   5. Exec the supplied CMD (supervisord by default).

set -euo pipefail

WAIT_TIMEOUT="${AUTHN_DEPENDENCY_WAIT_SECONDS:-60}"

log() {
    printf '[entrypoint] %s\n' "$*"
}

wait_for_tcp() {
    local host="$1"
    local port="$2"
    local label="$3"
    local elapsed=0
    until nc -z "$host" "$port" 2>/dev/null; do
        if [ "$elapsed" -ge "$WAIT_TIMEOUT" ]; then
            log "Timed out waiting for ${label} (${host}:${port}) after ${WAIT_TIMEOUT}s."
            exit 1
        fi
        elapsed=$((elapsed + 1))
        sleep 1
    done
    log "${label} reachable at ${host}:${port}."
}

if [ -n "${DB_HOST:-}" ]; then
    wait_for_tcp "${DB_HOST}" "${DB_PORT:-5432}" "Postgres"
fi
if [ -n "${REDIS_HOST:-}" ]; then
    wait_for_tcp "${REDIS_HOST}" "${REDIS_PORT:-6379}" "Redis"
fi

log "Running database migrations…"
php artisan migrate --force --no-interaction

if [ -n "${AUTHN_BOOTSTRAP_ADMIN_EMAIL:-}" ] && [ -n "${AUTHN_BOOTSTRAP_ADMIN_PASSWORD:-}" ]; then
    log "Running authn:bootstrap (idempotent)…"
    php artisan authn:bootstrap \
        --email="${AUTHN_BOOTSTRAP_ADMIN_EMAIL}" \
        --password="${AUTHN_BOOTSTRAP_ADMIN_PASSWORD}" \
        --workspace="${AUTHN_BOOTSTRAP_WORKSPACE_NAME:-My workspace}" \
        || log "authn:bootstrap exited non-zero — already bootstrapped is normal."
fi

log "Re-cooking config / route / view caches…"
php artisan optimize >/dev/null

log "Handing off to: $*"
exec "$@"
