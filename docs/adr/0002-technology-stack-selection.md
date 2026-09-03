# ADR-0002: Technology Stack Selection

- Status: Accepted
- Date: 2026-09-03
- Deciders: Founder / Product Owner; CTO / Technical Partner
- Owners: Web; Backend; Data; Platform Operations
- Related: [`ENGINEERING_BLUEPRINT.md`](../../ENGINEERING_BLUEPRINT.md), [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md), SRS sections 6, 9, and 12; [ADR-0001](0001-modular-monolith-architecture.md)

## Context

The authoritative references lock the core technology direction needed to implement hore.my. The frontend must provide an installable, responsive, accessible PWA. The backend must preserve the modular-monolith and single-database transaction direction. The platform must support durable background work, caching/coordination, encrypted document storage, horizontal scaling, observability, backup, and recovery.

This ADR records only the named technologies and capabilities already approved. It does not decide framework patch versions, package managers, build tools, testing libraries, hosting vendor, cloud services, container orchestration, authentication provider, observability vendor, AI/OCR provider, or object-storage vendor.

## Decision drivers

- Preserve explicitly locked Founder and project technology decisions.
- Support the task-driven PWA experience and WCAG 2.2 AA target.
- Maintain an exact, transactional accounting system of record.
- Support asynchronous workloads without allowing them to compromise posting.
- Enable stateless horizontal scaling toward 3,000 authenticated concurrent sessions.
- Avoid provider lock-in where the references require adapters or abstraction.

## Considered options

1. The locked stack: Nuxt 3, Vue 3, TypeScript, Tailwind CSS, installable PWA, Laravel modular monolith, PostgreSQL, Redis, and encrypted/versioned object storage.
2. A different frontend or backend framework stack.
3. A serverless or microservices-first stack.

## Decision

hore.my will use:

- **Frontend:** Nuxt 3, Vue 3, TypeScript, Tailwind CSS, and an installable PWA.
- **Backend:** Laravel implemented as the modular monolith defined by ADR-0001.
- **Primary database:** PostgreSQL as the authoritative relational and transactional database.
- **Queue, cache, and coordination:** Redis, with workload-specific queue policies.
- **Documents and evidence:** encrypted, versioned object storage.
- **Platform capabilities:** CDN, WAF, load balancing, rate limiting, centralized logs, metrics, traces, backup, point-in-time recovery, and restore drills.

Application instances must remain stateless and capable of horizontal scaling. Development, test, staging, and production credentials and data must be isolated. PostgreSQL remains authoritative for ledger writes and immediate reads after financial writes; caches and read replicas cannot be presented as authoritative without freshness controls.

Dependencies must be pinned through lockfiles or immutable references and must pass the Engineering Blueprint's quality gates.

Exact runtime versions, Laravel and Tailwind major versions, Node.js and PHP versions, repository package manager, API style, testing tools, local-development container strategy, deployment platform, managed-service vendors, and initial coverage threshold are explicitly deferred. They require selection before their relevant foundation work and must be recorded through an ADR when material. No deferred choice may replace or weaken the technologies locked above without a superseding ADR and the required authority.

## Consequences

### Positive

- The product has a coherent, explicitly approved frontend, backend, data, and asynchronous-processing baseline.
- PostgreSQL supports the required transactional posting boundary.
- Redis supports isolated queues and coordination while application instances stay stateless.
- Provider/vendor selections remain reversible where the sources do not decide them.

### Negative

- The project operates two primary application ecosystems: TypeScript/Vue and PHP/Laravel.
- Redis and object storage add operational dependencies beyond the primary database.
- Several foundation choices remain unresolved and must be decided before reproducible builds and CI can be finalized.

### Risks and mitigations

- **Risk:** Unpinned versions produce inconsistent or unsupported builds. **Mitigation:** Decide supported runtimes during foundation work, commit lockfiles, and enforce dependency/security gates.
- **Risk:** Redis loss affects critical background work. **Mitigation:** PostgreSQL outbox records remain the durable handoff source; test Redis failure and worker restart behavior.
- **Risk:** Cached or replicated data misrepresents a recent financial action. **Mitigation:** Serve immediate post-write financial reads from the primary database and enforce freshness controls.
- **Risk:** Vendor selection silently becomes architecture. **Mitigation:** Use adapters where required and record material provider choices separately.

## Validation

- Reproducible builds verify the selected frontend and backend frameworks and pinned dependencies.
- Integration tests verify PostgreSQL transaction behavior, Redis queue behavior, encrypted object storage, and recovery paths.
- Accessibility and responsive tests verify WCAG 2.2 AA goals and operation from 320px through desktop.
- Load tests validate 3,000 representative authenticated concurrent sessions and the approved latency gates.
- Resilience tests cover Redis failure, worker restart, AI outage, external timeouts, backup restoration, and point-in-time recovery.

## Rollout and rollback

The stack is introduced during project-foundation work before product implementation. Runtime and vendor selections are added only after their deferred decisions are approved. Production artifacts must be immutable and promoted unchanged across environments.

A framework, primary database, or architectural replacement requires a superseding ADR and a tested migration and recovery plan. Provider implementations behind an existing approved abstraction may be changed incrementally if contracts, auditability, security, and data reconciliation remain intact.

## Compliance

The selected stack must be configured for tenant isolation, least privilege, encryption in transit and at rest, secret separation, immutable audit records, retention controls, and Malaysian localization. Dependency licenses and vulnerabilities are release gates. This ADR does not select a compliance certification or introduce additional product scope.
