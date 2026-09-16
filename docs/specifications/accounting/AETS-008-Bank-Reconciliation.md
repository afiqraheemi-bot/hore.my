# AETS-008: Bank Import, Matching & Reconciliation

- Status: Draft
- Version: 0.1.1
- Effective date: Not effective — pending required review
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer; Founder / Product Owner for the unresolved workflow decisions in §12
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-002](AETS-002-Accounting-Invariants.md), [AETS-003](AETS-003-Money-Specification.md), [AETS-004](AETS-004-Journal-Posting-Model.md), [AETS-007](AETS-007-Posting-Command.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../adr/0005-ai-provider-abstraction.md), [ADR-0007](../../adr/0007-money-representation-strategy.md), [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md), System Requirements Specification v1.0 BM

## 1. Purpose and status warning

This document is the planned AETS-008 home for bank-statement import, matching, and reconciliation. It records the requirements already locked by higher authority, inventories the current implementation, and exposes the decisions that remain unresolved.

**This Draft is not governing implementation and is not an accuracy or release certification.** It must not be marked `Active` until the review required by AETS-000 §8.2 is recorded and every Founder/Product decision in §12 is resolved. Existing code described here is evidence of current behaviour, not authority for that behaviour.

## 2. Scope

### 2.1 In scope

- Tenant-owned Bank Accounts linked to an Asset Account.
- Import of supported bank-statement files into normalized Bank Transactions.
- File-level and row-level duplicate resistance.
- Candidate matching between imported Bank Transactions and existing Posted Journals.
- Explicit human confirmation of a match.
- Reconciliation periods, opening/closing balances, difference computation, lifecycle, completion, and audited reopening.
- Exact MYR Money, tenant isolation, atomicity, auditability, and zero-difference completion.

### 2.2 Out of scope or not yet buildable

- Live bank feeds, which the Master Context explicitly excludes from MVP.
- Multi-currency banking or reconciliation.
- Autonomous AI posting or autonomous reconciliation completion.
- Invoicing/document match targets not yet exposed by the current matching implementation.
- A fuzzy scoring model, tolerance windows, partial matches, split matches, and many-to-one/one-to-many matching semantics until §12 is resolved.
- The Reconciliation Report deferred by AETS-009.
- Any claim that BNK-002 guided column mapping is implemented; the current parser accepts one canonical CSV layout only.

## 3. Authority-locked requirements

The following requirements are already mandatory regardless of this Draft's lifecycle:

1. A completed Reconciliation has an exact RM0.00 unexplained difference.
2. Import and reconciliation are duplicate-resistant by construction; the same bank source line cannot create a duplicate accounting event.
3. Bank import itself has no ledger effect. AI or deterministic matching may propose or identify a candidate, but only Accounting Core's existing deterministic posting boundary may create a Journal.
4. Every Money value is exact integer minor units in the current approved stack; binary floating point is prohibited.
5. Every read, write, relationship, uniqueness boundary, and audit history is tenant-isolated.
6. A completed Reconciliation requires an explicit, reasoned, audited reopen before it may be changed.
7. Material multi-write operations are atomic and fail without partial persistent effect.
8. Bank source data and confirmed matching/audit facts are not silently overwritten or deleted.

## 4. Current implemented model (pending ratification)

The repository currently contains these Banking aggregates and records:

- `BankAccount`: a Tenant-owned real-world bank account linked to one Asset Account; only the last four account-number digits may be stored.
- `ImportBatch`: one statement-import attempt with SHA-256 file hash and exact row/inserted/duplicate counts.
- `BankTransaction`: one immutable normalized statement row containing date, description, amount, currency, direction, optional running balance, and reference.
- `MatchCandidate`: a transient read model; never persisted and never posts.
- `BankTransactionMatch`: an immutable confirmed link from a Bank Transaction to a Posted Journal, with source type, rationale, actor, and time.
- `Reconciliation`: one Bank Account and inclusive date range with stated opening/closing balances and lifecycle state.
- `ReconciliationReopening`: append-only reason, actor, and time history for each reopen.

## 5. Import contract already evidenced

The current implementation:

- accepts a canonical CSV header: `date,description,amount,direction,balance,reference`;
- parses the entire file before any write, rejecting a malformed file atomically;
- represents directions as `MoneyIn` or `MoneyOut`, never ledger `Debit`/`Credit` terminology;
- stores exact amounts through the approved Money persistence adapter;
- hashes exact file bytes for file-level replay within one Bank Account;
- uses a Bank-Account-scoped natural key for overlapping-row duplicate detection;
- commits the ImportBatch and all new Bank Transactions in one database transaction; and
- creates no Journal and has no posting permission.

This does **not** yet prove concurrent identical imports return one deterministic replay result. The database rejects duplication, but the service-level concurrent replay contract remains an open assurance item (§12.6).

## 6. Matching contract already evidenced

Current candidate generation is read-only and requires:

- the same Tenant;
- a Posted Journal;
- the Bank Account's linked Account;
- exact amount and Financial Date;
- `MoneyIn` mapped to Debit on the linked Asset Account and `MoneyOut` mapped to Credit; and
- an identifiable Expense, Income, Transfer, or Owner Equity source record.

Confirmation revalidates candidacy at confirmation time and persists only a confirmed immutable Match. A Bank Transaction may have at most one confirmed Match at the database boundary.

These exact-candidate rules and supported source types describe current code; they remain Draft until reviewed. The cardinality of Journal-to-Bank-Transaction matches is intentionally unresolved (§12.2), particularly for Transfers with bank rows on both sides.

## 7. Reconciliation calculation and lifecycle

The current difference calculation is:

`implied closing = opening balance + MoneyIn - MoneyOut`

for every imported Bank Transaction on the selected Bank Account whose transaction date is within the inclusive Reconciliation period. The unexplained difference compares the stated closing balance against this implied closing balance using exact Money.

The current lifecycle is:

`Draft → InReview → Balanced → Completed`

- `markBalanced` rejects any nonzero live difference.
- `complete` recomputes and rejects any nonzero live difference instead of trusting stale `Balanced` state.
- lifecycle transitions lock the Reconciliation row and persist transactionally.
- `reopen` accepts only `Completed`, requires a non-empty reason, returns to `Draft`, clears `completed_at`, and atomically appends a reopening-history record.

Whether matching completeness is also a prerequisite for `Balanced` or `Completed` is not decided by the current authoritative text and is deferred to §12.1.

## 8. Persistence integrity

The production migration chain now enforces tenant-coherent composite relationships for:

- Import Batch → Bank Account;
- Bank Transaction → Bank Account and its own Import Batch;
- Match → Bank Transaction and Journal;
- Reconciliation → Bank Account; and
- Reconciliation Reopening → Reconciliation.

Invalid pre-existing cross-tenant rows make the hardening migration fail. It never rewrites, reassigns, or silently accepts ambiguous financial data.

The migration chain also enforces the already-approved persistence facts that import counts are non-negative and reconcile exactly, Bank Transaction amounts are non-negative magnitudes, Banking currency is MYR, `Completed` and `completed_at` are mutually consistent, and reopening reasons are non-blank. These constraints do not decide whether statement or reconciliation *balances* may be negative; that separate overdraft/sign-policy question remains open (§12.9).

## 9. Candidate invariants for review

The following IDs are proposed so the corresponding Draft ATS can trace current evidence. They do not become normative until this document is Active.

- `BNK-001` — Import has zero ledger effect.
- `BNK-002` — Money and bank direction are exact and unambiguous.
- `BNK-003` — A malformed statement has zero persistent effect.
- `BNK-004` — Import Batch and inserted Bank Transactions commit atomically.
- `BNK-005` — File replay and overlapping-row import cannot create duplicate Bank Transactions.
- `BNK-006` — Every Banking relationship and operation is tenant-isolated.
- `BNK-007` — Candidate generation is read-only and confirmation revalidates current eligibility.
- `BNK-008` — A Bank Transaction has at most one immutable confirmed Match.
- `BNK-009` — Difference arithmetic is exact over the inclusive period.
- `BNK-010` — `Balanced` and `Completed` require exact zero live difference.
- `BNK-011` — Lifecycle transitions are serialized and atomic.
- `BNK-012` — Reopening is explicit, reasoned, append-only audited, and atomic.
- `BNK-013` — AI/matching cannot post or bypass Accounting Core.

## 10. Failure semantics

- Parse or validation failure writes no Import Batch and no Bank Transaction.
- A persistence failure during import rolls back the whole batch.
- A failed lifecycle transition leaves state and completion time unchanged.
- A failed reopening-history insert rolls back the state change.
- A nonzero difference leaves a Reconciliation uncompleted.
- A tenant mismatch is rejected by both tenant-scoped application queries and database constraints.
- No failure may be translated into a successful accounting or reconciliation result.

## 11. Required proof before activation

- Current unit and real-PostgreSQL integration suites mapped in ATS-008.
- Real-PostgreSQL migration constraint and rollback proof for all six Banking tables.
- Fault injection for every material multi-write operation.
- Concurrency proof for import replay, match confirmation, and lifecycle transitions.
- Explicit tenant-isolation proof at repository, service, HTTP, and schema boundaries.
- A connected bank-statement segment in the approved Proof of Accuracy golden dataset when AETS-012 is activated.
- Accounting Domain Reviewer confirmation of difference sign semantics, lifecycle prerequisites, and transfer matching cardinality.

## 12. Unresolved decisions — activation blockers

### 12.1 Completion prerequisite

The sources require exact RM0.00 and say matched items are tracked, but do not settle whether every in-period Bank Transaction must be matched/explained before completion. Founder/Product and Accounting Domain Reviewer must decide this explicitly.

### 12.2 Match cardinality and split/transfer behaviour

The current database guarantees one Match per Bank Transaction but permits a Journal to have multiple Matches. The current suggester excludes an already-matched Journal. Those facts conflict for plausible two-sided Transfer reconciliation and must not be resolved by inference.

### 12.3 Late imports affecting a Completed Reconciliation

Difference is currently a live projection over imported rows. A later import inside a completed period can therefore make a previously exact Reconciliation nonzero after completion. The approved contract must choose and specify one coherent policy—such as immutable membership/snapshot semantics, blocking affected imports until audited reopen, or another reviewed design—before the implementation can claim `BNK-010` continuously rather than only at the completion instant.

### 12.4 Overlapping Reconciliations

No authoritative source decides whether periods for one Bank Account may overlap, or whether only one open Reconciliation may exist for a period. The database currently permits both.

### 12.5 Guided file mapping and parser variants

SRS BNK-002 calls for guided mapping of unrecognized formats. The current canonical CSV parser does not implement it. Accepted formats, mapping persistence, validation UX, and parser-provider boundaries require product specification before implementation.

### 12.6 Concurrency outcome contracts

Database uniqueness prevents duplicate rows, but the required caller-visible result for concurrent identical file imports and concurrent Match confirmations is not yet specified: deterministic replay, focused conflict, or another response. Integrity is fail-safe; API semantics remain undecided.

### 12.7 Matching score and rationale

The SRS requires score and rationale. The current exact matcher persists rationale but models its score only implicitly as exact. The canonical score representation, calibration, thresholds, and human-review rules are not decided.

### 12.8 Additional match targets and reporting

Invoice/document targets and the Reconciliation Report remain incomplete/deferred. Their contracts must be added without weakening the invariants above.

### 12.9 Negative statement balances and overdrafts

The active Money model is non-negative, while a real bank statement's running, opening, or closing balance may be negative for an overdraft. Current code cannot faithfully reconstruct such a balance and difference computation explicitly fails when it needs a negative intermediate value. The Accounting Domain Reviewer must define signed-balance semantics and their relationship to unsigned Money magnitudes before support is implemented; this Draft does not prohibit legitimate overdrafts by adding an arbitrary non-negative balance constraint.

## 13. Activation checklist

- [ ] CTO / Technical Partner review recorded.
- [ ] Accounting Domain Reviewer approval recorded.
- [ ] Founder / Product decisions in §12 recorded where required.
- [ ] Every accepted decision converted into normative MUST-level language.
- [ ] ATS-008 updated from evidence inventory to complete normative traceability.
- [ ] All required tests pass against real PostgreSQL with zero skips.
- [ ] AETS/ATS indexes and versions updated.

## Changelog

- **0.1.1 (2026-09-15):** Records database enforcement of approved exact-value/state facts and explicitly defers negative bank-balance/overdraft sign semantics. No unresolved policy was selected.
- **0.1.0 (2026-09-15):** Initial Draft. Records locked requirements, current implementation evidence, schema tenant hardening, and eight unresolved decision groups. No new authority, MVP scope, or accuracy claim is introduced.
