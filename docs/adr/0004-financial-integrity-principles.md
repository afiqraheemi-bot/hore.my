# ADR-0004: Financial Integrity Principles

- Status: Accepted
- Date: 2026-09-03
- Deciders: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Owners: Accounting Core; Transactions; Banking and Reconciliation; Invoicing; Reporting; Audit and Security
- Related: [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md), SRS sections 4.4, 4.5, 4.8, 5.1, 10.5, and 11; [ADR-0001](0001-modular-monolith-architecture.md); [ADR-0006](0006-transactional-outbox-pattern.md); [ADR-0007](0007-money-representation-strategy.md)

## Context

hore.my handles financial records for Malaysian sole proprietors and microbusinesses. The product may automate evidence extraction and propose accounting treatment, but its authoritative records must never lose, duplicate, imbalance, or silently change money. Every reported figure must remain traceable to journals, decisions, and evidence.

The authoritative sources lock double-entry balance, atomic and idempotent posting, append-only posted journals, reversal/replacement correction, exact money, zero-difference completed reconciliation, rebuildable projections, tenant isolation, and complete auditability. They also make financial integrity failures release blockers.

This ADR defines non-negotiable integrity invariants. It does not decide the chart of accounts, tax treatments, numbering schemes, accounting policies, database table design, approval thresholds, or detailed period-close workflow.

## Decision drivers

- Prevent partial, duplicate, imbalanced, cross-tenant, or silent financial mutation.
- Preserve an authoritative and reproducible ledger history.
- Make correction and reconciliation explicit and auditable.
- Keep AI and external integrations outside ledger authority.
- Support evidence-backed reporting and recovery.

## Considered options

1. A controlled double-entry, append-only ledger with atomic/idempotent commands and reversal/replacement correction.
2. Mutable transaction records corrected in place.
3. Eventual, independently written accounting records reconciled after the fact.

## Decision

The Accounting Core is the exclusive authority for ledger posting and must enforce all of the following:

- Every posted journal has at least two lines and total debit equals total credit exactly.
- Posting of the journal, journal lines, evidence linkage, audit event, and required outbox event commits or rolls back as one PostgreSQL transaction.
- Every material command carries an idempotency key and source fingerprint. Repeated or concurrent execution returns the original economic result and cannot create another posting.
- Posted journals and lines are append-only and cannot be updated or deleted through the application.
- Corrections use a linked reversal that mirrors the original effect and, when needed, a separately linked replacement. The original record and correction reason remain visible.
- The authoritative balance comes from the ledger. Projections and reports are derived and rebuildable from posted journals.
- A closed period rejects ordinary posting. Reopen is explicit, reasoned, highly verified, and audited.
- Payment allocation cannot exceed the unallocated amount and cannot be consumed twice.
- The same bank source line cannot be posted twice for the same economic event.
- Completed reconciliation has RM0.00 unexplained difference.
- Money uses only the exact representations permitted by ADR-0007.
- Every financial record has immutable tenant ownership; application, query, database, and storage controls must prevent cross-tenant access.
- Every material action records actor, tenant, source, time, and applicable policy/model version. Evidence retains its original file, hash, uploader, timestamp, and links.
- AI and proposal-producing modules cannot write ledger records. Only a validated accounting command accepted through deterministic Accounting Core controls can post.
- Network calls and external work do not execute inside the ledger transaction; ADR-0006 governs their handoff.

Financial dates, posting timestamps, and source timestamps remain distinct. External identifiers and original payload metadata required for reconciliation are retained. Operator support access is read-restricted and cannot directly mutate ledger records.

## Consequences

### Positive

- Financial history is reproducible, explainable, and resistant to silent corruption.
- Retries and concurrency cannot create duplicate economic effects.
- Corrections preserve a complete audit chain.
- Reports and projections can be independently reconciled to the ledger and evidence.

### Negative

- Corrections and period management require more explicit workflows than mutable records.
- Storage grows because posted history and audit evidence are retained.
- Posting commands and migrations require more rigorous transaction and concurrency testing.

### Risks and mitigations

- **Risk:** An adapter or module bypasses Accounting Core. **Mitigation:** Enforce least-privilege database access, module contracts, architecture tests, and security tests.
- **Risk:** A retry posts twice. **Mitigation:** Combine idempotency keys, source fingerprints, uniqueness constraints, and concurrency tests.
- **Risk:** A projection drifts from the ledger. **Mitigation:** Make projections rebuildable and run scheduled integrity monitors with critical alerts.
- **Risk:** A correction obscures the original event. **Mitigation:** Require linked reversal/replacement records and append-only audit history.
- **Risk:** Restore introduces inconsistency. **Mitigation:** Test backup restoration, point-in-time recovery, projection rebuild, and post-restore reconciliation.

## Validation

- Unit and property-based tests prove balance, reversal neutrality, exact arithmetic, allocation bounds, state transitions, and idempotency.
- Integration and fault-injection tests prove atomic commit/rollback with no partial posting.
- Concurrency tests prove repeated commands and imports produce one economic result.
- Golden datasets reconcile receipts, bank data, invoices, journals, trial balance, and reports exactly.
- Integrity monitors detect imbalance, duplicate risk, broken evidence references, and projection drift and generate critical alerts.
- Tenant-isolation and privilege tests prove unauthorized and cross-tenant posting fails.

Any imbalanced trial balance, unexplained reconciliation difference, duplicate-posting risk, failed tenant isolation, or unproved backup/restore remains a release blocker.

## Rollout and rollback

These invariants must exist before Accounting Core behavior is considered complete and before full AI workflows. Proof of Accuracy using approved golden datasets precedes full AI implementation.

Posted financial history is never rolled back by destructive mutation. Application releases roll forward or roll back only if schema and commands remain compatible; financial corrections use reversal/replacement. Migration recovery must protect committed ledger history and include reconciliation evidence.

## Compliance

The design must support retention obligations, privacy controls, least privilege, encryption, immutable financial/security auditing, and traceability for professional review. Deletion requests cannot override lawful retention or ledger integrity. This ADR does not claim tax advice, audit acceptance, or autonomous compliance and introduces no scope beyond the approved MVP.
