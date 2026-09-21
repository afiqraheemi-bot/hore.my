# Architecture Decision Records

This directory contains the Architecture Decision Records (ADRs) for hore.my.

ADRs use the template and governance rules in [`ENGINEERING_BLUEPRINT.md`](../../ENGINEERING_BLUEPRINT.md). Accepted ADRs are immutable records of a decision. A later decision does not rewrite an accepted ADR; it adds a new ADR and marks the earlier record `Superseded` or `Deprecated` with a link to its replacement.

## Authority

Decisions are interpreted using the precedence documented in [`docs/product/reference/README.md`](../product/reference/README.md):

1. Latest Founder instruction
2. `HORE_MY_PROJECT_INSTRUCTIONS.txt`
3. `HORE_MY_MASTER_CONTEXT.md`
4. System Requirements Specification
5. Detailed Project Proposal

## Lifecycle

`Proposed` → `Accepted` → `Superseded` or `Deprecated`. Rejected proposals use `Rejected`.

## Index

| ADR | Status | Decision |
| --- | --- | --- |
| [ADR-0001](0001-modular-monolith-architecture.md) | Accepted | Use a Laravel modular monolith with explicit domain boundaries. |
| [ADR-0002](0002-technology-stack-selection.md) | Accepted | Use the locked Nuxt, Vue, TypeScript, Tailwind, PWA, Laravel, PostgreSQL, and Redis stack. |
| [ADR-0003](0003-repository-structure.md) | Accepted | Use the blueprint's monorepo structure and preserve module ownership. |
| [ADR-0004](0004-financial-integrity-principles.md) | Accepted | Make posting balanced, atomic, idempotent, append-only, reversible, auditable, and tenant-isolated. |
| [ADR-0005](0005-ai-provider-abstraction.md) | Accepted | Isolate AI/OCR providers and restrict AI to proposals and interpretation. |
| [ADR-0006](0006-transactional-outbox-pattern.md) | Accepted | Commit outbox events atomically and process external work asynchronously and idempotently. |
| [ADR-0007](0007-money-representation-strategy.md) | Accepted | Use PostgreSQL BIGINT storing integer minor units as canonical Money persistence; prohibit binary floating point everywhere. |
| [ADR-0008](0008-identity-authentication-tenancy-strategy.md) | Accepted | Use Laravel Sanctum SPA cookie authentication, a REST/JSON `/api/v1` API, and a Tenant-owns-its-owner-User tenancy shape. |
| [ADR-0009](0009-workspace-task-module-boundary.md) | Accepted | Introduce a Workspace and Task module that depends only on Accounting Core's existing Command contracts (never the reverse), governed by a new sibling specification series (WTS) outside AETS. |
| [ADR-0010](0010-staging-deployment-architecture.md) | Accepted | Run staging on a single VPS via Docker Compose + Caddy, same registrable domain split only by port (no app code changes), resolving the deployment-topology question ADR-0002/ADR-0008 had deferred. |

## Creating an ADR

- Use the next zero-padded sequence number and a short kebab-case filename.
- Copy the complete template from the Engineering Blueprint.
- Cite the authoritative requirements or earlier ADRs that constrain the decision.
- State unresolved implementation details explicitly rather than treating them as decisions.
- Do not add product scope through an ADR.
