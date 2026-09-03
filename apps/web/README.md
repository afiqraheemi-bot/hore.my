# hore.my Web

## Purpose

This directory contains the Nuxt frontend application for hore.my, as established by [ADR-0002](../../docs/adr/0002-technology-stack-selection.md) (amended to Nuxt 4) and [ADR-0003](../../docs/adr/0003-repository-structure.md).

This bootstrap contains framework foundations only. It does not implement product pages, authentication, API integration, state management, a UI component framework, PWA behavior, or any business or accounting workflow.

## Owner

The frontend is owned through the repository [`CODEOWNERS`](../../CODEOWNERS) default policy.

## Public interface

No product route or page has been introduced. The template's default `app.vue` renders Nuxt's own welcome screen as a framework bootstrap artifact and is not an approved hore.my product screen.

## Dependencies

- Node.js `^22.19.0 || ^24.11.0 || >=26.0.0` (as required by Nuxt 4)
- npm as the package manager
- Nuxt 4, Vue 3, and Vue Router (runtime dependencies)
- TypeScript and `vue-tsc` (development-only, for type checking)

`package-lock.json` is committed and is the reproducible source for dependency installation. Use `npm install` for setup; do not substitute an unconstrained dependency update.

No state management library (e.g. Pinia), UI component framework, or PWA module is included. These remain deferred until a dedicated foundation task authorizes them.

## Local validation

From `apps/web`:

```text
npm install
npm run build
npm run typecheck
```

`npm run dev` starts a local development server. `npm run build` produces a production build under `.output/` (git-ignored, not committed).

## Docker development environment

A minimal Docker Compose environment (`api`, `web`, `postgres`, `redis`, `mailpit`) is defined at the repository root in [`docker-compose.yml`](../../docker-compose.yml). See [`docs/development/setup.md`](../../docs/development/setup.md) for usage. It is development-only and introduces no production infrastructure or architecture decision.
