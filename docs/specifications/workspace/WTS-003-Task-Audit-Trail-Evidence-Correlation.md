# WTS-003: Task Audit Trail & Evidence Correlation

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-09
- Owner: Workspace and Task (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [WTS-000](WTS-000.md); [WTS-001](WTS-001-Task-Proposal-State-Model.md); [WTS-002](WTS-002-Proposal-to-Command-Translation.md); [ADR-0009](../../adr/0009-workspace-task-module-boundary.md); [AETS-010](../accounting/AETS-010-Audit-Trail-Evidence-Linkage.md)

## 1. Purpose

This document defines how the Workspace lifecycle, its approved Proposal, and the Accounting Core result form one tenant-safe, deterministically reconstructable story without merging their separately owned audit stores.

## 2. Scope

In scope are Task transition ordering, correlation identifiers, Actor and reason preservation, nullable Evidence Reference propagation, successful Journal correlation, and failure semantics. Audit search/export UI, a combined reporting API, Evidence storage/retention, AI provenance, and correction-Task workflow remain outside this document and retain their existing owners.

## 3. Separate stores, one story

Workspace owns `tasks`, `proposals`, and `task_transitions`. Accounting Core owns `journals`, `audit_events`, and `journal_evidence_links`. Neither module writes the other's audit records.

The reconstructable chain for a completed Task is:

```text
(Tenant, Task ID)
  -> ordered Task transitions
  -> current Proposal and optional Evidence Reference
  -> Task.result_journal_id
  -> same-Tenant Journal
  -> Accounting Audit Event and optional Journal Evidence Link
```

The composite foreign keys remain the database authority for same-Tenant Task/Proposal/Journal relationships. Correlation never relies on description text, amount matching, timestamps, or fuzzy inference.

## 4. Deterministic transition order

Every persisted Task transition carries a database-assigned, immutable, monotonically increasing sequence. Lifecycle reads order by this sequence. Timestamp remains the human and operational time of the transition but is not an ordering key: multiple transitions may legitimately occur within the same timestamp precision.

Sequence gaps caused by rolled-back transactions are valid and convey no business meaning. The sequence is global to the table; Task ID and Tenant ID select a lifecycle, while sequence orders its records. It must never be caller supplied or reused.

## 5. Actor, reason, and evidence

- Every transition preserves its authenticated Actor Reference.
- Reject and Cancel preserve their required human reason; designed Accounting rejection preserves its real failure reason.
- A supplied Evidence Reference remains opaque and exact. It may appear on the Proposal, capture/processing transitions, the resulting business command, and the Journal Evidence Link.
- Absence of Evidence never causes a reference or linkage to be fabricated.
- Evidence tenant ownership remains deferred until Document Processing defines a persisted Evidence aggregate, exactly as AETS-010 records.

## 6. Completion and failure

A `Completed` Task stores the exact Journal ID returned by the existing Recording Service. The Task-to-Journal composite foreign key prevents a cross-Tenant or nonexistent result. The resulting Accounting Audit Event independently records the same Tenant, Journal, and approving Actor under Accounting Core rules.

A `Failed`, `Rejected`, or `Cancelled` Task has no result Journal link. Unexpected infrastructure failure rolls back the attempted approval transitions and does not create a misleading terminal audit story.

## 7. Query boundary

Current Task detail may expose the Task, Proposal, and ordered transition history. This document does not add an Accounting Audit Trail query API: AETS-010 explicitly defers that API and UI. A future combined projection may read each owner's public query contract once Accounting audit querying is approved; it must not bypass module ownership with cross-module writes.

## 8. Invariants (`TAC-NNN`)

- **TAC-001:** Task transitions are append-only and returned in deterministic database-assigned sequence order; timestamp ties never make lifecycle order ambiguous.
- **TAC-002:** Tenant ID and Task ID form the stable Workspace correlation; correlation never uses fuzzy financial or textual matching.
- **TAC-003:** Every transition preserves Actor, from-state, to-state, timestamp, nullable reason, and nullable Evidence Reference exactly.
- **TAC-004:** A completed Task references exactly the same-Tenant Journal returned by command execution, and Accounting Core independently records its required Audit Event.
- **TAC-005:** Where Evidence is supplied, its exact opaque reference propagates through the Proposal and existing command/linkage path; none is fabricated when absent.
- **TAC-006:** Workspace transition records never enter `audit_events`, and Accounting Core never writes `task_transitions`.
- **TAC-007:** Terminal Tasks without a successful posting carry no result Journal; unexpected failure creates neither a false terminal state nor partial Journal/audit data.
- **TAC-008:** Every lifecycle and correlation read is Tenant-scoped; another Tenant cannot observe a Task, Proposal, transition, Journal correlation, or Evidence Reference.
- **TAC-009:** A combined Accounting audit query/API/UI remains deferred to its Accounting/Reporting owner; Workspace does not invent direct access to Accounting persistence.

## 9. Required tests

WT-003 must trace every invariant to concrete tests, including tied timestamps with deterministic sequence order, complete success correlation, evidence-present and evidence-absent paths, terminal no-Journal behavior, rollback on unexpected failure, module-boundary structure, and cross-Tenant HTTP isolation. Persistence and foreign-key claims require real PostgreSQL.

## 10. Deferred decisions

- Accounting Audit Trail query/export API and combined operator UI.
- Persisted Evidence aggregate, tenant ownership, storage, retention, and deletion policy.
- AI model/prompt/schema provenance and policy-version population.
- Correction Task linkage to an original completed Task.

No deferred capability is implied by this specification.

## Changelog

- **1.0.0 (2026-09-09):** Initial specification. Formalizes the existing separate-store correlation and closes timestamp-tie ambiguity with a database-assigned transition sequence.
