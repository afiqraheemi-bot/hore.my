# ADR-0001: Modular Monolith Architecture

- Status: Accepted
- Date: 2026-09-03
- Deciders: Founder / Product Owner; CTO / Technical Partner
- Owners: All backend module owners; Platform Operations
- Related: [`ENGINEERING_BLUEPRINT.md`](../../ENGINEERING_BLUEPRINT.md), [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md), SRS sections 5 and 9

## Context

hore.my must deliver an accounting workspace whose ledger posting is atomic, balanced, idempotent, auditable, and isolated by tenant. The MVP covers identity and tenant, onboarding, task-driven workspace, documents, transactions, banking and reconciliation, invoicing, MyInvois, accounting, reporting, AI orchestration, and operational governance. These capabilities need strong boundaries without introducing distributed transactions into the accounting core.

The authoritative references explicitly select a Laravel modular monolith. They state that this approach keeps journal transactions inside one database boundary, reduces distributed-transaction risk, and accelerates MVP development. They also require stateless application instances, independently isolated queues, explicit contracts, and later module separation only when scale or ownership provides a demonstrated reason.

This ADR decides the backend application shape and module interaction model. It does not select hosting topology, source-code namespace conventions, Laravel package conventions, or criteria for a future service extraction beyond the evidence requirement below.

## Decision drivers

- Preserve a single reliable transaction boundary for accounting posting.
- Enforce exclusive ownership of journals and posting by the Accounting module.
- Support the locked MVP modules without microservice operational overhead.
- Allow horizontal scaling of stateless application instances and workers.
- Keep module extraction possible when supported by measured scale or ownership needs.
- Preserve tenant isolation, auditability, and explicit contracts.

## Considered options

1. Laravel modular monolith with explicit module boundaries and one primary PostgreSQL transactional boundary.
2. Traditional layered monolith without domain module boundaries.
3. Independently deployed microservices from the start.

## Decision

hore.my will use a **Laravel modular monolith** for the backend.

The monolith is divided into these authoritative business capabilities:

1. Identity and Tenant, including Business Profile and Onboarding.
2. Workspace and Task.
3. Document Processing.
4. Transactions.
5. Banking and Reconciliation.
6. Customers, Quotations, and Invoicing.
7. MyInvois / Compliance.
8. Accounting Core.
9. Reporting and Compliance Pack.
10. AI Orchestration.
11. Audit, Security, and Operations.

These capability labels may be composed into the module groupings stated in the SRS, but their responsibilities must not be lost or silently reassigned.

Each module owns its rules and data mutations and exposes an explicit public application contract. Cross-module access must use those contracts or domain/application events, never imports into module internals. Cyclic dependencies are prohibited. The Accounting module exclusively owns accounts, journals, journal lines, posting, accounting periods, and ledger invariants. No other module may mutate ledger records directly. Reporting reads the ledger or rebuildable projections and cannot mutate source financial records. Workspace/Task and AI Orchestration may create proposals but cannot post directly.

All records and operations must preserve tenant isolation at application and database boundaries. Material actions must remain attributable to actor, source, policy/model version where applicable, and time.

External and asynchronous work must occur outside the ledger transaction using the transactional outbox defined by ADR-0006. Application instances remain stateless and horizontally scalable; worker and queue isolation does not turn modules into independently owned services.

A module may be extracted into a separately deployed service only through a later ADR supported by demonstrated scale or ownership needs, with explicit treatment of transactionality, compatibility, tenant isolation, observability, and operational cost.

## Consequences

### Positive

- Ledger writes can use one atomic PostgreSQL transaction boundary.
- Domain ownership and dependency rules remain explicit without premature distribution.
- MVP delivery has lower deployment and operational complexity.
- Stateless web and worker processes can still scale horizontally.
- Module contracts provide a controlled path to later extraction.

### Negative

- Boundary discipline must be enforced inside a single deployable codebase.
- Modules share deployment cadence and may share failure domains.
- A large monolith can accumulate coupling if architecture checks and ownership reviews are neglected.

### Risks and mitigations

- **Risk:** Modules bypass contracts through direct database or internal code access. **Mitigation:** Enforce public entry points, dependency rules, database ownership conventions, architecture tests, and `CODEOWNERS` review.
- **Risk:** Shared packages become a miscellaneous coupling layer. **Mitigation:** Require a stable responsibility, defined consumers, and no application ownership in shared packages.
- **Risk:** A slow external integration extends or corrupts posting. **Mitigation:** Prohibit network calls inside database transactions and use ADR-0006.
- **Risk:** Tenant data crosses module or query boundaries. **Mitigation:** Carry immutable `tenant_id`, enforce isolation in application and database access, and run cross-tenant security tests.

## Validation

- Architecture checks demonstrate permitted dependency direction and detect cycles or internal cross-module imports.
- Integration tests prove journal, lines, evidence references, audit event, and outbox record commit or roll back together.
- Security tests prove tenant isolation at API, query, and storage boundaries.
- Load and resilience tests demonstrate the required stateless scale-out behavior without weakening ledger integrity.
- Reviews confirm only Accounting can perform posting and source-record mutation.

## Rollout and rollback

This decision governs the initial backend structure. Modules and contracts are introduced as their approved MVP capabilities are implemented. Because no prior application architecture exists, rollout requires no data or service migration.

Reversal of this decision requires a superseding ADR. Any extraction must be incremental, preserve existing contracts during transition, and provide a verified reconciliation and rollback or roll-forward plan. The authoritative ledger boundary must not be split without proof that all financial invariants remain enforceable.

## Compliance

The architecture must support least privilege, encryption, environment isolation, append-only financial and security audit records, Malaysian data and privacy obligations, and traceability of material actions. Operator access remains restricted and cannot directly mutate the ledger. This ADR adds no new MVP capability.
