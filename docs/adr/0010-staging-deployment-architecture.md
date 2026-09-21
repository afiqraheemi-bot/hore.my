# ADR-0010: Staging Deployment Architecture

- Status: Accepted
- Date: 2026-09-21
- Deciders: Founder / Product Owner
- Owners: Accounting Core (see [`CODEOWNERS`](../../CODEOWNERS))
- Related: [ADR-0002](0002-technology-stack-selection.md) (deferred the deployment-platform decision);
  [ADR-0008](0008-identity-authentication-tenancy-strategy.md) (flagged the exact risk this ADR
  resolves for staging); `docker-compose.staging.yml`; `infrastructure/caddy/Caddyfile`;
  `docs/operations/staging-deployment.md`

## Context

[ADR-0002](0002-technology-stack-selection.md) explicitly deferred "deployment platform," requiring it
"be recorded through an ADR when material." [ADR-0008](0008-identity-authentication-tenancy-strategy.md)
named a specific, then-hypothetical risk: "Sanctum's SPA mode requires the API and the SPA to share a
registrable domain... this constrains, but does not block, eventual deployment topology and must be
revisited if a materially different frontend hosting arrangement is ever chosen" and "revisit at
deployment-architecture time, not now." This is that time, for staging specifically (production
deployment remains a separate, later decision — the Founder's standing instruction is NO-GO on real
production until explicit new sign-off).

Before this ADR, the repository had no deployment tooling at all: `docker-compose.yml` and both
`Dockerfile`s are explicitly self-labeled development-only, no CI workflow builds or pushes an image,
and `docs/operations/` held only a backup-restore runbook. This was a genuine blank slate, not an
oversight — ADR-0002 deferred it on purpose, pending a concrete need.

## Decision drivers

- Reuse this repo's own already-proven local-dev topology as closely as possible, minimizing new,
  untested surface area.
- No frontend or backend application code should need to change to support staging — infrastructure
  and configuration only.
- Match the Founder's explicit choice of a VPS + Docker Compose over a managed PaaS (Railway/Render):
  full control, low cost, consistent with the existing Compose-based dev workflow.
- Keep staging's own operational complexity proportional to what the application actually needs today
  — no queue worker exists anywhere in the codebase yet, so no production-grade process supervisor is
  justified either.

## Considered options

1. **Same registrable domain, different ports** (chosen) — e.g. `staging.hore.my` for the web app,
   `staging.hore.my:8443` for the API, both TLS-terminated by Caddy. Identical scheme to local dev
   (`localhost:3000`/`localhost:8000`), which `apps/web/app/composables/useApi.ts` already implements
   by design (API origin hostname reused from `window.location.hostname`, only the port configured via
   `NUXT_PUBLIC_API_PORT`) — because Sanctum's `SameSite=Lax` cookie only requires a shared
   *registrable domain*, not a shared port. Zero application code changes.
2. **Subdomain split** (e.g. `api.staging.hore.my`) — also valid under `SameSite=Lax` (subdomains of
   the same registrable domain remain "same site"), and arguably more conventional. Rejected for now:
   `useApi.ts` has no full-origin override today, only a port override, so this would require a real
   (if small) frontend change to add one. Noted as a legitimate future option if a materially different
   topology (e.g. separate hosts entirely) is ever needed — at which point a full-origin override
   becomes necessary regardless.
3. **Managed PaaS (Railway/Render)** — rejected per the Founder's own explicit choice (this ADR records
   that choice; it does not re-litigate it).

## Decision

Staging runs on a single VPS via `docker-compose.staging.yml`, built from a new `production` target
added to both `apps/api/Dockerfile` and `apps/web/Dockerfile` (the existing `development` target is
untouched — `docker-compose.yml` now targets it explicitly rather than relying on Docker's "last stage
in the file wins" default). Caddy terminates TLS automatically (Let's Encrypt) for two site blocks on
one domain, distinguished by port, per option 1 above — see `infrastructure/caddy/Caddyfile`.

Each service image is built with no bind mount (`docker-compose.yml`'s dev-only pattern), consistent
with ADR-0002's "Production artifacts must be immutable and promoted unchanged across environments" —
the image itself is the deployable unit. Real secrets live in `apps/api/.env.staging` (gitignored,
created once on the VPS from the committed `apps/api/.env.staging.example` template) and a
root-level `.env` (gitignored, Compose-level `STAGING_DOMAIN`/`POSTGRES_PASSWORD` substitution only).

Deploys are triggered by a push to the `staging` branch via a new, separate GitHub Actions workflow
(`.github/workflows/deploy-staging.yml`) that SSHes into the VPS and rebuilds — deliberately not folded
into `backend-ci.yml`/`frontend-ci.yml`/`e2e-ci.yml`, which continue gating every PR against `main`
unchanged.

`php artisan serve` (already used in dev) remains the staging API process — no php-fpm, no nginx, no
queue worker/supervisor. This is a deliberate simplification, not an oversight: no `ShouldQueue` job
exists anywhere in this codebase today, so there is nothing a worker process would do.

## Consequences

### Positive

- Zero application code changes required — the exact same `useApi.ts` hostname+port scheme already
  proven by this repo's own full Playwright E2E suite (against the `development` Docker target) applies
  unchanged to staging (the `production` target).
- Both `production` Dockerfile targets and the full `docker-compose.staging.yml` stack (`caddy`
  excluded, since automatic TLS cannot be exercised without a real domain) were built, booted, migrated,
  and smoke-tested directly before this ADR was written — not merely designed on paper. See
  `docs/operations/staging-deployment.md` §4 for exactly what was and wasn't verified, including two
  real configuration mistakes (a `DB_PASSWORD`/`POSTGRES_PASSWORD` mismatch, and a placeholder
  `APP_KEY`) found and documented, not just imagined.
- `development` and `production` Dockerfile targets share a single `base` stage (system packages, PHP
  extensions, Composer binary) — no duplicated, potentially-drifting dependency list between the two.

### Negative

- Exposing the API on a non-standard HTTPS port (`8443`) is slightly unconventional compared to a
  subdomain split, though technically sound (Caddy TLS-terminates any port equally). A future
  production topology may still choose the subdomain approach instead (option 2 above), which would
  then require the `useApi.ts` full-origin override this decision currently avoids needing.
- No zero-downtime deploy — each push to `staging` briefly restarts the whole stack. Acceptable for a
  single-environment staging server; would need revisiting for production.
- No queue worker, no horizontal scaling, no managed database — this stack is sized for staging
  traffic and today's feature set (no queued jobs), not for production load. Revisiting this is
  explicitly out of scope here (see Rollout and rollback).

### Risks and mitigations

- **Risk**: `DB_PASSWORD` (Laravel's own env) and `POSTGRES_PASSWORD` (the Postgres container's own env,
  a separate Compose-level variable) silently drift out of sync, since nothing enforces they match.
  **Mitigation**: this exact failure was reproduced during verification and is now explicitly called
  out in both `apps/api/.env.staging.example`'s own inline comment and
  `docs/operations/staging-deployment.md` §2 step 5.
- **Risk**: A future materially-different hosting arrangement (e.g. API and web on genuinely separate
  hosts) breaks the hostname-reuse assumption `useApi.ts` depends on. **Mitigation**: unchanged from
  ADR-0008's own mitigation — `SANCTUM_STATEFUL_DOMAINS` and the API-origin derivation are both
  configuration/small-code concerns, not architectural ones; revisit at that time, exactly as ADR-0008
  already anticipated.

## Validation

- `docker compose -f docker-compose.staging.yml config --quiet` — passes (mirrors
  `repository-ci.yml`'s existing check for the dev compose file).
- Both `production` Dockerfile targets built successfully and passed a direct smoke test: `apps/api`
  answered `GET /up` with `200`; `apps/web` answered `GET /login` with `200`.
- The full `docker-compose.staging.yml` stack (minus `caddy`) was brought up under an isolated Compose
  project name (no collision with the running dev stack — a real near-miss during verification,
  corrected by using an explicit `-p` project name), every migration ran cleanly, and a real
  `POST /api/v1/register` request against the production-mode API correctly returned `422` for an
  invalid payload — proving real business logic (not just a static health check) executes correctly
  under `--no-dev --optimize-autoloader`.
- Full backend PHPUnit suite (1918 tests) and full Playwright E2E suite (44 tests) both re-confirmed
  green against the local dev stack after every Dockerfile/Compose change in this ADR, proving none of
  it altered dev's own existing behavior.
- **Not yet performed** (the Founder's own remaining step, tracked in
  `docs/operations/staging-deployment.md` §4): a real browser register/login smoke test against a live
  staging URL, once DNS and the VPS are provisioned.

## Rollout and rollback

This ADR covers staging only. Production deployment architecture remains a separate, future decision —
nothing here authorizes production use, consistent with the Founder's standing NO-GO on production
until explicit new sign-off. Rollback is trivial and low-risk: `docker-compose.staging.yml`,
`infrastructure/caddy/`, `.github/workflows/deploy-staging.yml`, and the `production` Dockerfile targets
are all purely additive — deleting them returns the repository to its exact pre-ADR state, and
`docker-compose.yml`'s own dev workflow is unaffected either way (verified directly, not assumed).

## Compliance

No `RPT-NNN`, `JRN-NNN`, or other AETS-series invariant is affected — this ADR is infrastructure-only
and touches no accounting computation, ledger write, or report derivation.
