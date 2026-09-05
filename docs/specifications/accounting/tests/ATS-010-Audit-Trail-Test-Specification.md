# ATS-010: Audit Trail & Evidence Linkage Test Specification

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-06
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](../AETS-000.md), [AETS-001](../AETS-001-Accounting-Terminology.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-004](../AETS-004-Journal-Posting-Model.md), [AETS-007](../AETS-007-Posting-Command.md), [AETS-010](../AETS-010-Audit-Trail-Evidence-Linkage.md); [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../../adr/0006-transactional-outbox-pattern.md)

## 1. Purpose

This document is the normative Accounting Test Specification (ATS) proving compliance with [AETS-010: Audit Trail & Evidence Linkage](../AETS-010-Audit-Trail-Evidence-Linkage.md). Every test defined here is identified by a stable ID (`AUD-T001`–`AUD-T017`) and traced to the `AUD-NNN` invariant(s) it proves (§5).

Unlike [ATS-004](ATS-004-Journal-Posting-Test-Specification.md) and [ATS-007](ATS-007-Posting-Pipeline-Test-Specification.md), which were each written before their corresponding implementation, this document was authored alongside AETS-010's implementation in the same milestone (M6) — every test ID below traces to a concrete, already-passing test, not a future target. This does not relax the traceability requirement; it means the matrix in §5 can be verified directly against the current test suite rather than taken on faith.

## 2. Scope

### 2.1 In scope

- A complete traceability matrix from `AUD-001`–`AUD-010` to test IDs.
- Concrete test cases for Audit Event production (both M4 Posting and M5 Journal Correction), Evidence Reference construction and linkage, atomicity (fault-injection), replay behavior, tenant isolation, and schema constraints.

### 2.2 Out of scope

- Anything AETS-010 itself defers (AETS-010 §2.2, §16): Evidence's full schema/retention/storage, Evidence tenant-ownership validation, Outbox Event schema/dispatcher, Audit Trail query API/UI/export, and policy/model version semantics. No test below claims coverage of any of these.
- Money, Journal, Chart of Accounts, and Posting Command's own construction/validation tests — already fully specified by [ATS-003](ATS-003-Money-Test-Specification.md), [ATS-004](ATS-004-Journal-Posting-Test-Specification.md), [ATS-005](ATS-005-Chart-of-Accounts-Test-Specification.md), and [ATS-007](ATS-007-Posting-Pipeline-Test-Specification.md).

## 3. Authority

This document is subordinate to [AETS-010](../AETS-010-Audit-Trail-Evidence-Linkage.md) and the authority hierarchy in [AETS-000 §3](../AETS-000.md#3-authority-hierarchy). No contradiction between this document and AETS-010 was found while drafting it.

## 4. Test Strategy

- **Prove the invariant, not the implementation.** Every test traces to an `AUD-NNN` invariant.
- **Real PostgreSQL for atomicity and constraints.** Every atomicity, tenant-isolation, and schema-constraint test runs against a real PostgreSQL instance — never SQLite — mirroring [ATS-004 §4](ATS-004-Journal-Posting-Test-Specification.md#4-test-strategy)'s established rule, since these properties are exactly the ones a mocked or in-memory substitute cannot prove.
- **Both producers, not just one.** Since AETS-010 §10 defines three producers (`JournalPosted`, `JournalReversed`, `JournalReplaced`) sharing one Audit Event schema and one atomicity mechanism, this document requires the fault-injection and content-correctness proof be repeated for the M4 Posting producer and at least one M5 Correction producer (Reversal) — not assumed identical from the M4 case alone, since they are different executor classes. `JournalReplaced` shares `JournalReversed`'s exact code path (the same private orchestration core in `JournalCorrectionTransactionalExecutor`) and is proven only for Audit Action content correctness, not re-proven for atomicity/fault-injection — a deliberate, stated scope reduction, not an oversight.

## 5. Traceability matrix

| Invariant | Test IDs |
| --- | --- |
| AUD-001 | AUD-T001, AUD-T002, AUD-T003 |
| AUD-002 | AUD-T004 |
| AUD-003 | AUD-T001, AUD-T002, AUD-T005 |
| AUD-004 | AUD-T006, AUD-T007 |
| AUD-005 | AUD-T008 |
| AUD-006 | AUD-T009 |
| AUD-007 | *(Not independently testable — see §7.)* |
| AUD-008 | AUD-T010, AUD-T011 |
| AUD-009 | AUD-T012 |
| AUD-010 | AUD-T013 |

## 6. Test cases

| Test ID | Description | Level |
| --- | --- | --- |
| AUD-T001 | A successful, newly-posted `PostingCommand` execution produces exactly one Audit Event with Action `JournalPosted` (`AUD-001`, `AUD-003`). | Integration |
| AUD-T002 | A successful, newly-posted Reversal execution produces exactly one Audit Event with Action `JournalReversed` (`AUD-001`, `AUD-003`). | Integration |
| AUD-T003 | A successful, newly-posted Replacement execution produces exactly one Audit Event with Action `JournalReplaced` (`AUD-001`). | Integration |
| AUD-T004 | An idempotent replay of a previously-posted command produces zero additional Audit Events (`AUD-002`). | Integration |
| AUD-T005 | An Audit Event's persisted row carries the correct Tenant, Actor, Source, Action, Journal, and a non-null database-assigned occurrence time (`AUD-003`). | Integration |
| AUD-T006 | A forced, non-duplicate constraint failure on the `audit_events` insert during a Posting Command execution rolls back the entire transaction — zero Journal, Line, idempotency-mapping, and Audit Event rows afterward (`AUD-004`). | Integration, fault-injection |
| AUD-T007 | The same fault-injection proof as AUD-T006, for a Reversal execution (`AUD-004`). | Integration, fault-injection |
| AUD-T008 | No code path in `AuditEventRepository` exposes an update or delete operation for an Audit Event (`AUD-005`). | Architecture (by inspection — `AuditEventRepository` exposes exactly one public method, `record()`). |
| AUD-T009 | `EvidenceReference` exposes exactly `of`, `toString`, `equals` — no method that parses, interprets, or classifies what the reference points to (`AUD-006`). | Unit |
| AUD-T010 | A Posting Command carrying Evidence References produces exactly one linkage row per reference, correctly associated with the posted Journal (`AUD-008`). | Integration |
| AUD-T011 | A forced, non-duplicate constraint failure on the `journal_evidence_links` insert rolls back the entire transaction, including the Audit Event already written earlier in the same transaction body (`AUD-008`). | Integration, fault-injection |
| AUD-T012 | A Posting Command carrying no Evidence References produces zero linkage rows for the posted Journal (`AUD-009`). | Integration |
| AUD-T013 | `ReverseJournalCommand` and `ReplaceJournalCommand` carry no Evidence Reference field — verified by inspection of both classes' constructors (`AUD-010`). | Architecture |
| AUD-T014 | `audit_events.action` rejects a non-canonical value via its `CHECK` constraint. | Integration, schema |
| AUD-T015 | An `audit_events` or `journal_evidence_links` row cannot reference a nonexistent Journal, or a Journal belonging to a different Tenant, via the composite foreign key. | Integration, schema, tenant isolation |
| AUD-T016 | The same Evidence Reference cannot be linked to the same Journal twice, via the `UNIQUE (tenant_id, journal_id, evidence_reference)` constraint. | Integration, schema |
| AUD-T017 | Both migrations are reversible through the real Laravel migrator (`migrate:rollback` then `migrate` re-applies cleanly). | Integration, schema |

## 7. AUD-007 — not independently testable

`AUD-007` ("No fabricated Evidence") restates a rule that governs *how* an Evidence Reference is produced upstream of Accounting Core (by a future AI Orchestration or Document Processing component, neither of which exists yet) — it is not a property `EvidenceReference::of()` or any current Accounting Core code path can observe or reject, since an opaque reference carries no marker distinguishing a fabricated value from a real one by construction (AETS-010 §8: opacity is required precisely so Accounting Core never attempts this). No test is specified for it here; enforcement belongs to whichever future component actually produces Evidence References, exactly as [AETS-007 §20](../AETS-007-Posting-Command.md#20-ai-originated-command-rules) already treats the parallel "AI MUST NOT fabricate Evidence" rule as a process constraint, not a Posting Command-level check.

## 8. Deferred Items

- Evidence's own schema/retention/storage tests — deferred alongside AETS-010's own deferral (§2.2), to a future Document Processing test specification.
- Evidence tenant-ownership validation tests — deferred alongside AETS-010 §11's own tracked gap; no test can prove a check that does not yet exist without inventing behavior AETS-010 does not define.
- Outbox Event tests — deferred alongside AETS-010's own exclusion (§2.2); no Outbox Event schema exists to test.

## Changelog

- **1.0.0 (2026-09-06):** Initial version, authored alongside AETS-010's implementation (M6).
