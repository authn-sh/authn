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

make up                  # production-shaped stack (app + worker + scheduler + postgres + redis)
docker compose logs -f app
# First boot prints the workspace + secret/publishable key pair exactly once.
# Sign in at https://${AUTHN_DASHBOARD_HOST} with the bootstrap operator
# credentials.
```

Workers scale independently from the web container:

```bash
docker compose up -d --scale worker=4
```

### Local customisations

`docker-compose.override.yml` is gitignored. Drop one in your checkout
and Docker Compose auto-merges it on every command — handy for setting
your own ports, mail driver, S3 endpoint, etc.

## Dev workflow

```bash
make dev                 # app + worker + scheduler (dev image) + vite + mailpit
make logs                # tail app
make logs s=worker
make sh                  # shell into app
make test                # Pest suite inside the app container
make scale w=3           # scale worker
make down                # stop; add V=1 to drop volumes
```

`docker-compose.dev.yml` is the dev layer — same nginx + php-fpm stack
the production image runs (no `php artisan serve`), with selective
source bind-mounts for HMR. Vite runs in its own container against the
shared source mount, exposes `:5173`. Mailpit captures outbound mail at
`http://localhost:8025`.

The Pest suite also runs without Docker against in-memory SQLite per
`phpunit.xml`:

```bash
composer install
php artisan test
```

## Architecture

- `routes/bapi.php` — server-to-server BAPI, secured by `Authorization: Bearer sk_…`.
- `routes/fapi.php` — browser-facing FAPI + the hosted Account Portal.
- `routes/dashboard.php` — operator UI (Inertia + React).
- `routes/console.php` — background-maintenance schedule (AU-19).

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
