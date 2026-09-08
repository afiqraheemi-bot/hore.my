# End-to-end tests (Playwright)

Real-browser tests against the full stack — never a mock, never the
Nuxt/Vue test-instance harness alone. Proves what unit tests, `npm run
build`, and TypeScript cannot: that the actual pages render, submit
real HTTP requests, and reflect the real result back correctly.

**Why this directory exists.** Earlier in this project's history, the
Work Queue / Task Detail / Human Confirmation flow (ADR-0009) was
verified manually with an ad hoc Playwright script run from a
scratch directory — real verification, but not committed, not
CI-enforced, and not repeatable by anyone else. A later QA pass
flagged this as a real gap: "no committed automated tests... commit
describes manual Playwright verification, but that test is not saved
in the repository and cannot be run again by CI." This directory
closes that gap.

## Prerequisites

The full stack must already be running and reachable at
`http://localhost:3000` (web) and `http://localhost:8000` (api):

```sh
docker compose up -d --wait
docker compose exec -T api php artisan migrate --force
```

This suite does not start the stack itself — CI's own workflow step
does that before invoking `playwright test`, exactly as a developer
running this suite locally must.

## Running locally

```sh
cd apps/web
npx playwright install --with-deps chromium   # once per machine
npm run test:e2e
```

## Conventions

- Every spec registers its own fresh Tenant/User (`support/fixtures.ts`)
  rather than depending on pre-seeded demo/admin accounts — no shared
  state between specs or parallel runs.
- Specs drive the real UI (click buttons, fill forms) and assert on
  what actually renders — never a direct API call standing in for a
  user action the UI is supposed to perform.
- `workers: 1` (see `playwright.config.ts`) — this suite creates real
  Tenants/Accounts/Tasks against one shared database; parallel workers
  would need per-worker tenant isolation this suite does not yet
  build. A future addition may lift this once that's worth the
  complexity.
