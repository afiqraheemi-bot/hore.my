# ADR-0006: Transactional Outbox Pattern

- Status: Accepted
- Date: 2026-09-03
- Deciders: Founder / Product Owner; CTO / Technical Partner
- Owners: Accounting Core; MyInvois / Compliance; Document Processing; Notifications; Platform Operations
- Related: [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md), SRS sections 4.7, 4.8, 6, and 9.1; [ADR-0001](0001-modular-monolith-architecture.md); [ADR-0004](0004-financial-integrity-principles.md)

## Context

Ledger posting may need to trigger MyInvois, notifications, document processing, projections, or other asynchronous work. Performing network calls inside the ledger transaction would extend locks, couple financial availability to external systems, and still leave an unsafe gap between a committed posting and external delivery. Writing directly to Redis after commit can also lose work if the process fails between database commit and queue publication.

The authoritative references require the journal, lines, evidence, audit event, and outbox event to commit or roll back together. Workers must process outbox work with idempotent handlers, durable retry, dead-letter policy, isolated queue policies, and correlated telemetry. MyInvois submission and retries must not produce duplicate local or external submission.

This ADR decides delivery semantics for external and asynchronous side effects. It does not select an outbox table schema, dispatcher library, polling or notification mechanism, ordering partition, retry intervals, dead-letter storage, retention window, or Redis queue implementation.

## Decision drivers

- Eliminate the dual-write gap between PostgreSQL state and asynchronous publication.
- Keep external latency and failure outside financial database transactions.
- Preserve atomic posting and complete auditability.
- Make retries safe and prevent duplicate economic or external effects.
- Isolate accounting, MyInvois, document, AI, report, and notification workloads.
- Support recovery after Redis, worker, or external-provider failure.

## Considered options

1. PostgreSQL transactional outbox with asynchronous idempotent dispatch and handling.
2. Publish directly to Redis before or after committing domain state.
3. Call external services synchronously inside the database transaction.
4. Use distributed transactions across the database, queue, and external systems.

## Decision

All external or asynchronous work caused by an authoritative state change will use a **transactional outbox**.

The originating PostgreSQL transaction writes the domain change, required evidence linkage, audit event, and outbox event atomically. If any required write fails, the whole transaction rolls back. No external network call or Redis publication is part of that transaction.

After commit, a dispatcher publishes pending outbox events to the appropriate isolated queue. Consumers process events asynchronously. Delivery is at least once; therefore dispatchers, handlers, and external submission adapters must be idempotent. Retries must not create a second ledger posting, duplicate document result, duplicate notification effect where preventable, or duplicate MyInvois submission.

Every outbox event must have a stable identifier, immutable tenant ownership, event type and contract version, originating aggregate or command reference, occurrence time, correlation/causation information, and payload sufficient for the owned handler without uncontrolled cross-module data access. Sensitive payload content is minimized and protected according to classification.

Processing state, attempt history, errors, and terminal dead-letter outcome must be observable and auditable. Retry is durable across process and Redis failures. Poison or exhausted events move to a dead-letter path and generate an owned operational alert; they are not silently discarded. Replay requires authorization and retains the original identity and attempt history.

Accounting, MyInvois, document, AI, reporting, and notification workloads have separate queue policies so backlog or failure in OCR/AI or an external provider cannot block ledger posting. Immediate reads following a financial write use authoritative PostgreSQL state rather than waiting for an asynchronous projection.

The concrete schema, payload envelope encoding, claim/locking algorithm, publication mechanism, ordering guarantees, retry/backoff limits, dead-letter implementation, retention/archive policy, and operational replay tooling are deferred. Their design must preserve the semantics above and the target RPO/RTO.

## Consequences

### Positive

- A committed state change cannot omit its recorded intent for asynchronous work.
- Financial transactions do not wait on external systems.
- Worker and Redis restarts can recover work from durable PostgreSQL state.
- Queue isolation prevents non-accounting backlog from blocking posting.

### Negative

- Consumers observe side effects eventually rather than in the originating transaction.
- At-least-once delivery requires idempotency throughout dispatch and handling.
- Outbox retention, monitoring, replay, and dead-letter operations add complexity.

### Risks and mitigations

- **Risk:** Duplicate delivery creates duplicate external effects. **Mitigation:** Use stable event/idempotency identifiers, source fingerprints, uniqueness controls, and idempotent external adapters.
- **Risk:** An event remains unpublished. **Mitigation:** Monitor outbox age and backlog, use durable retries, and alert on service-level thresholds.
- **Risk:** Event ordering produces invalid state transitions. **Mitigation:** Define ordering only where a consuming workflow requires it and validate transitions idempotently; exact mechanism is deferred.
- **Risk:** Payload leaks tenant or sensitive data. **Mitigation:** Make tenant ownership immutable, minimize payloads, encrypt sensitive content, and enforce worker authorization.
- **Risk:** Replay causes a second economic action. **Mitigation:** Preserve original identifiers and make replay use the same idempotency controls.

## Validation

- Integration and fault-injection tests prove domain data and outbox commit or roll back together.
- Crash tests at pre-commit, post-commit/pre-publication, post-publication/pre-acknowledgement, and handler completion boundaries prove no lost intent and no duplicate economic effect.
- Redis failure and worker restart tests prove durable recovery.
- MyInvois timeout/retry tests prove one logical submission and auditable attempts/status.
- Queue isolation tests prove AI/OCR or document backlog does not block posting.
- Monitoring tests prove stale events, exhausted retries, dead letters, and mismatches trigger alerts with correlation context.

## Rollout and rollback

The outbox foundation must exist before any workflow relies on external/asynchronous side effects from authoritative writes. Producers and consumers adopt versioned contracts incrementally. During changes, consumers must remain compatible with supported event versions.

Rollback preserves outbox and attempt history. A deployment may revert only while a compatible consumer remains available; otherwise it must roll forward. Pending or dead-letter events are reconciled and replayed through authorized idempotent tooling, never deleted to conceal failure.

## Compliance

Outbox and attempt records must preserve tenant isolation, least privilege, encryption, retention controls, and an append-only audit trail for material actions. Payloads must exclude unnecessary personal or sensitive data. External submission environment and credentials remain isolated. This ADR authorizes no new external integration or MVP capability.
