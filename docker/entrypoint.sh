#!/usr/bin/env bash
#
# Container entrypoint. Runs as www-data via tini. Order of operations:
#
#   1. Wait for Postgres + Redis to accept connections (up to 60s).
#   2. If AUTHN_RUN_MIGRATIONS=true (the web container, by default):
#      - run `php artisan migrate --force` (idempotent),
#      - run `authn:bootstrap` when the AUTHN_BOOTSTRAP_ADMIN_* trio is set.
#      Worker / scheduler containers leave AUTHN_RUN_MIGRATIONS unset so
#      they don't race the web container during boot.
#   3. Re-cook config / route / view caches against the live env.
#   4. Exec the supplied CMD (supervisord, queue:work, schedule:work, …).

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

if [ "${AUTHN_RUN_MIGRATIONS:-false}" = "true" ]; then
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
fi

log "Re-cooking config / route / view caches…"
php artisan optimize >/dev/null

log "Handing off to: $*"
exec "$@"
