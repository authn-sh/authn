# authn.sh

Self-hostable authentication-as-a-service. Laravel-based server that ships
the Backend API (BAPI), Frontend API (FAPI), Account Portal, and Dashboard
in one container.

## Quickstart (Docker)

```bash
git clone https://github.com/authn-sh/authn.git
cd authn

cp .env.example .env
# Required edits in .env:
#   APP_KEY                          (run `openssl rand -base64 32` and prefix with `base64:`)
#   AUTHN_APP_URL                    (e.g. https://authn.example.com)
#   AUTHN_APP_HOST + subdomain hosts (or set AUTHN_ROUTING_MODE=path)
#   AUTHN_BOOTSTRAP_ADMIN_EMAIL      (operator email for first-boot setup)
#   AUTHN_BOOTSTRAP_ADMIN_PASSWORD   (initial operator password)

docker compose up -d
docker compose logs -f app
# First boot prints the workspace + secret/publishable key pair exactly once.
# Sign in at https://${AUTHN_DASHBOARD_HOST} with the bootstrap operator
# credentials.
```

The same compose file is used in dev — `docker-compose.override.yml` (auto-merged
by `docker compose up`) switches the app container to the `dev` Dockerfile
target, mounts the source for HMR, exposes Vite on `:5173`, and adds Mailpit on
`:8025` for outbound verification emails.

For a production-only stack (no dev tooling, no source mount), pass the
baseline file explicitly:

```bash
docker compose -f docker-compose.yml up -d
```

## Local development without Docker

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# In separate terminals (or via `composer run dev`):
php artisan serve
php artisan queue:work
php artisan horizon
npm run dev
```

The Pest suite runs against in-memory SQLite per `phpunit.xml`:

```bash
php artisan test
```

## Architecture

- `routes/bapi.php` — server-to-server BAPI, secured by `Authorization: Bearer sk_…`.
- `routes/fapi.php` — browser-facing FAPI + the hosted Account Portal.
- `routes/dashboard.php` — operator UI (Inertia + React).
- `routes/console.php` — background-maintenance schedule (AU-19).

PLAN.md (in the umbrella repo) is the v0.1 spec.

## Container layout

The published `authn/authn` image is a single container running:

- **nginx** (port 8080) → static assets + FastCGI to PHP-FPM,
- **php-fpm** (`docker/php/www.conf`),
- **queue worker** (`php artisan queue:work --queue=default,webhooks,mail`),
- **horizon** (`php artisan horizon`),
- **scheduler** (`php artisan schedule:work`).

`docker/entrypoint.sh` waits for Postgres + Redis, runs migrations, runs
`authn:bootstrap` when configured, then exec's supervisord.

## Releasing

`.github/workflows/release.yml` triggers on `v*.*.*` tags. It builds a
multi-arch image (linux/amd64 + linux/arm64) and publishes
`ghcr.io/authn-sh/authn:<tag>` to the public GitHub Container Registry,
along with an SBOM + provenance attestation. The Helm chart pulls those
images; the compose stack always builds from source.

## License

AGPL v3 — see LICENSE.
