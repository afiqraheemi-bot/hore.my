# Local Development Environment

This document describes the Docker Compose environment defined in [`docker-compose.yml`](../../docker-compose.yml) at the repository root. It is a local development convenience only: it has no production optimizations, no orchestration beyond Compose, and is not a deployment artifact. See [ADR-0002](../adr/0002-technology-stack-selection.md) for the runtime and vendor decisions this environment reflects.

## Services

| Service | Image / build | Host port(s) | Purpose |
| --- | --- | --- | --- |
| `api` | built from [`apps/api/Dockerfile`](../../apps/api/Dockerfile) (`php:8.5.10-cli`, `composer:2.10.3`) | 8000 | Laravel application server (`php artisan serve`) |
| `web` | built from [`apps/web/Dockerfile`](../../apps/web/Dockerfile) (`node:26.8.1-alpine`) | 3000 | Nuxt development server |
| `postgres` | `postgres:16.15-alpine` | 5432 | Primary database |
| `redis` | `redis:7.4.11-alpine` | 6379 | Cache, queue, and coordination |
| `mailpit` | `axllent/mailpit:v1.31.0` | 1025 (SMTP), 8025 (web UI) | Local mail capture, no real delivery |

No other services are included. Monitoring, a queue dashboard, object storage (MinIO), and Kubernetes are explicitly out of scope for this environment.

All image references are pinned to explicit patch-level versions (no `latest` or bare major/minor floating tags) as of 2026-09-03, resolved against the Docker Hub registry API. `mailpit`'s healthcheck (`/mailpit readyz`) was taken directly from the image's own Dockerfile at tag `v1.31.0` rather than assumed, since Mailpit ships as a minimal Alpine-based binary with its own built-in `HEALTHCHECK` and does not expose an HTTP health path for this purpose.

The `api` base image is pinned to `php:8.5.10-cli` rather than `8.3.x`: the committed `apps/api/composer.lock` resolved `symfony/*` packages that require PHP `>=8.4.1`, which only surfaced when building a clean container image (the host workstation's PHP 8.5.6 masked it). `8.5.10` satisfies both the lock file and `composer.json`'s own `">=8.3 <8.6"` range.

## Usage

From the repository root:

```bash
docker compose config   # validate and print the resolved configuration
docker compose up        # start all services in the foreground
```

Add `-d` to run in the background, and `docker compose down` to stop and remove containers. Named volumes (`postgres_data`, `api_vendor`, `web_node_modules`) persist across restarts; remove them with `docker compose down -v` to reset state.

## Verifying services

- **api**: `curl http://localhost:8000/up` should return a 200 response once Laravel has booted. This is Laravel's built-in liveness route and does not exercise the database or Redis.
- **web**: open `http://localhost:3000` in a browser or `curl http://localhost:3000`.
- **postgres**: `docker compose exec postgres pg_isready -U postgres -d hore_my`.
- **redis**: `docker compose exec redis redis-cli ping` should return `PONG`.
- **mailpit**: open `http://localhost:8025` for the web UI; the SMTP endpoint is `localhost:1025`.

`docker compose ps` reports each service's health status once its `healthcheck` has passed.

## Notes

- The `api` container copies `.env.example` to `.env` and runs `php artisan key:generate` on first start if no key is present (see [`apps/api/docker-entrypoint.sh`](../../apps/api/docker-entrypoint.sh)). No migrations are run automatically.
- Application source is bind-mounted for both `api` and `web` so local edits are reflected without rebuilding the image. `vendor/` and `node_modules/` are kept in named volumes so the container's own Linux-built dependencies are not overwritten by the host's.
- Credentials in `docker-compose.yml` (database and mail settings) are fixed, non-secret, local-only defaults and must never be reused outside this development environment.

## Two databases: `hore_my` and `hore_my_test`

The `postgres` service provisions **two** databases on first init (see [`infrastructure/postgres/init-test-database.sql`](../../infrastructure/postgres/init-test-database.sql)): `hore_my`, the application database, and `hore_my_test`, a dedicated database the test suite runs against — named to match the database [`backend-ci.yml`](../../.github/workflows/backend-ci.yml) has addressed via its own `DB_URL` all along; CI was already correctly isolated from the application database, only this local Docker Compose environment was not.

**This separation is load-bearing, not a convenience.** Several integration tests call `Artisan::call('migrate:fresh', ...)` or `truncate()` directly against the `pgsql` connection. Before this existed (P1-6, 2026-09-08 audit remediation), that connection resolved to `hore_my` during a normal `php artisan test` run too — an ordinary regression run destroyed real application data (confirmed in practice, not theoretical). `docker-compose.yml` deliberately does not set `APP_ENV` or `DB_DATABASE` as container-level environment variables for exactly this reason: doing so would silently override `phpunit.xml`'s own `<env>` declarations (`APP_ENV=testing`, `DB_DATABASE=hore_my_test`), the same way it previously did. `Tests\TestCase::setUp()` additionally refuses to run any test at all if the resolved database name doesn't end in `_test`, as a second, defense-in-depth check.

**Migrations for `hore_my_test` are not run automatically** (mirroring `hore_my`'s own no-auto-migration convention above) and must be applied once after the containers first start, or after `docker compose down -v`:

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=hore_my_test api php artisan migrate --force
```

`php artisan test` (or `vendor/bin/phpunit`) then runs entirely against `hore_my_test`, leaving `hore_my` untouched.
