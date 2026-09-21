# Staging Deployment Runbook

- Status: Ready — one-time VPS setup verified locally (production Docker images build, boot, migrate,
  and serve real business logic correctly); a live VPS deploy is the Founder's own next step
- Owner: Accounting Core (see [`CODEOWNERS`](../../CODEOWNERS))
- Related: [ADR-0010](../adr/0010-staging-deployment-architecture.md), `docker-compose.staging.yml`,
  `infrastructure/caddy/Caddyfile`, `.github/workflows/deploy-staging.yml`

## 1. Purpose

`docker-compose.yml` is explicitly local-development-only (`apps/api/Dockerfile` and
`apps/web/Dockerfile` say so too). This runbook covers the separate, production-targeted stack —
`docker-compose.staging.yml` — that runs on a real VPS, reachable over the internet with real TLS.
See [ADR-0010](../adr/0010-staging-deployment-architecture.md) for why this topology (one VPS, one
domain, two TLS-terminated ports, no app code changes) was chosen.

## 2. One-time VPS setup

1. **Provision a VPS.** Any provider (DigitalOcean, Hetzner, Linode, etc.); ~1-2 GB RAM is enough for
   staging traffic. Install Docker Engine and the Compose plugin (`docker compose version` should
   work).
2. **DNS.** Point an A record for your staging domain (e.g. `staging.hore.my`) at the VPS's public IP.
   Nothing else needs a DNS entry — both the web and API are served from this one hostname (see
   ADR-0010).
3. **Clone the repo** onto the VPS, e.g. `git clone <repo-url> /opt/hore.my && cd /opt/hore.my`.
4. **Compose-level substitution variables.** Create a root-level `.env` file (gitignored, separate
   from the Laravel app's own env file below — this one is read by `docker compose` itself for
   `${STAGING_DOMAIN}`/`${POSTGRES_PASSWORD}` substitution in `docker-compose.staging.yml`):
   ```
   STAGING_DOMAIN=staging.hore.my
   POSTGRES_PASSWORD=<a real, random password>
   ```
5. **The Laravel app's own env file.** Copy `apps/api/.env.staging.example` to `apps/api/.env.staging`
   and fill in every `CHANGE-ME` placeholder — most importantly:
   - `DB_PASSWORD` — **must exactly match** the `POSTGRES_PASSWORD` set in step 4's root `.env`. A
     mismatch here fails every database connection with no obvious error pointing at the cause
     (confirmed directly while validating this setup).
   - `APP_URL` / `CORS_ALLOWED_ORIGINS` / `SANCTUM_STATEFUL_DOMAINS` — your real staging domain, per
     the comments already in the example file.
   - `APP_KEY` — generate it from inside the built image itself (never by hand, never committed):
     ```
     docker compose -f docker-compose.staging.yml run --rm api php artisan key:generate --show
     ```
     paste the `base64:...` output back into `apps/api/.env.staging`.
6. **Deploy key for CI.** Generate a dedicated SSH key pair for automated deploys (`ssh-keygen -t
   ed25519 -f staging_deploy_key -N ""`), add the **public** key to the VPS's
   `~/.ssh/authorized_keys` for whichever user will run the deploy, then add these as **GitHub repo
   secrets** (Settings → Secrets and variables → Actions):
   - `STAGING_SSH_HOST` — the VPS's IP or hostname
   - `STAGING_SSH_USER` — the SSH user
   - `STAGING_SSH_KEY` — the **private** key's full contents
   - `STAGING_DEPLOY_PATH` — the repo path on the VPS (e.g. `/opt/hore.my`)
7. **First deploy (manual, one time only).** From the VPS:
   ```
   docker compose -f docker-compose.staging.yml up -d --build
   docker compose -f docker-compose.staging.yml exec -T api php artisan migrate --force
   ```
   Every deploy after this happens automatically — see §3.

## 3. Ongoing deploys

Push to the `staging` branch (or trigger `.github/workflows/deploy-staging.yml` manually via
`workflow_dispatch`) — the workflow SSHes into the VPS, `git reset --hard origin/staging`s the repo,
rebuilds and restarts the stack, and re-runs migrations. This workflow is deliberately separate from
`backend-ci.yml`/`frontend-ci.yml`/`e2e-ci.yml` (untouched) — those still gate every PR against `main`;
this one only runs against `staging`, after those have already passed on it.

## 4. What was verified before this runbook was written

Both `production` Dockerfile targets were built and smoke-tested directly (not merely assumed to
work):

- `apps/api` production image: builds with `composer install --no-dev --optimize-autoloader`, boots via
  `php artisan serve`, `GET /up` returns `200`.
- `apps/web` production image: builds via real `nuxt build` (Nitro), boots via `node
  .output/server/index.mjs`, serves `/login` with `200`.
- The full `docker-compose.staging.yml` stack (minus `caddy`, whose automatic TLS cannot be exercised
  without a real, internet-reachable domain) was brought up under an isolated Compose project name,
  migrated cleanly (every migration ran, `0` errors), and the API correctly executed real business
  logic in production mode — a real `POST /api/v1/register` request was validated (rejected a genuinely
  invalid payload with `422`, not a crash) once the dry run's own placeholder `DB_PASSWORD`/
  `SANCTUM_STATEFUL_DOMAINS`/`APP_KEY` values were corrected to real ones — each of those three
  failures, and their fixes, are exactly the mistakes §2 above now warns about, found by making them.
- **Not verified**: a full browser-driven register/login flow (Sanctum's CSRF cookie handshake) against
  the production image specifically — this requires either a real HTTPS domain or a more involved local
  HTTPS setup than a dry run justifies. This exact flow is already proven correct by this repo's own
  full Playwright E2E suite against the identical, unmodified application code (only the Docker build
  target differs) — but re-confirming it against a *live* staging URL after the first real deploy (§2
  step 7) is the one remaining verification step: register a real account, record a transaction, and
  confirm it in Reports.

## 5. What this setup does not cover (future work, not blockers)

- **True production scale**: no queue worker/supervisor, no php-fpm+nginx, no horizontal scaling, no
  managed database. There is currently no queued job anywhere in this codebase, so `php artisan serve`
  is sufficient for staging; revisit when approaching real production (see the standing Founder NO-GO
  on production until explicit sign-off).
- **Zero-downtime deploys**: each deploy briefly restarts the stack. Acceptable for staging.
- **Automated/off-site backups**: `infrastructure/postgres/backup.sh` (see
  [backup-restore-runbook.md](backup-restore-runbook.md)) can be cron'd manually on the VPS; nothing
  here does that automatically.
- **Observability/monitoring**: tracked separately as a production release gate (see
  `docs/product/screening/index.html`); not addressed here.
