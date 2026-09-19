# AETS-008: Bank Import, Matching & Reconciliation

- Status: Draft
- Version: 0.6.0
- Effective date: Not effective — pending required review
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer; Founder / Product Owner for the workflow decisions in §12 — **the Founder explicitly delegated §12's decisions to the CTO on 2026-09-16, in lieu of deciding each individually**; a qualified Accounting Domain Reviewer's independent sign-off on accounting correctness (distinct from the product/workflow judgment calls §12 required) remains outstanding and is still required before Activation (§13)
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-002](AETS-002-Accounting-Invariants.md), [AETS-003](AETS-003-Money-Specification.md), [AETS-004](AETS-004-Journal-Posting-Model.md), [AETS-007](AETS-007-Posting-Command.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../adr/0005-ai-provider-abstraction.md), [ADR-0007](../../adr/0007-money-representation-strategy.md), [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt), [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md), System Requirements Specification v1.0 BM

## 1. Purpose and status warning

This document is the planned AETS-008 home for bank-statement import, matching, and reconciliation. It records the requirements already locked by higher authority, inventories the current implementation, and records the Founder/Product decisions §12 now resolves.

**This Draft is not governing implementation and is not an accuracy or release certification.** It must not be marked `Active` until the review required by AETS-000 §8.2 is recorded, every decision in §12 is implemented (not merely decided) and proven, and the Accounting Domain Reviewer sign-off in §13 is recorded. As of v0.2.0, every §12 decision has been made — under explicit Founder delegation to the CTO — but §12's own text describes the decided policy, not yet the implemented behaviour; existing code described elsewhere in this document remains evidence of current behaviour, not authority for it, until each decision is built and tested.

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
- A fuzzy scoring model, tolerance windows, partial matches, and many-to-one/one-to-many matching semantics beyond the single two-leg Transfer exception §12.2 decides.
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

The database-level proof that concurrent identical imports never duplicate exists (`f7f05c9`); §12.6 now ratifies deterministic replay as the caller-visible contract this must also satisfy at the service/HTTP boundary, which is not yet separately proven.

### 5.1 Import format: XLSX added (2026-09-16)

`BankStatementFileFormat::Xlsx` is a second accepted file format for the **identical** fixed v1 schema §5 already describes — never a second, divergent schema, and never SRS BNK-002's own still-deferred guided mapping of an *unrecognized* format (§12.5 unchanged, `BNK-T053` still deferred). `XlsxBankStatementParser` and the original `CsvBankStatementParser` both delegate header validation and row parsing to one shared `BankStatementRowParser`, so a CSV upload and an XLSX upload of "the same statement" always validate and normalize identically. Two cell-type quirks a plain-text CSV cannot exercise are normalized before that shared validation ever runs: a native Excel serial-date cell is converted to the identical `Y-m-d` string a text cell would already carry, and a native numeric amount/balance cell is reformatted to the Tenant Currency's own exact decimal scale. Format is resolved from the uploaded file's own extension at the HTTP boundary (`BankStatementImportController`), never sniffed from file content.

## 6. Matching contract already evidenced

Current candidate generation is read-only and requires:

- the same Tenant;
- a Posted Journal;
- the Bank Account's linked Account;
- exact amount and Financial Date;
- `MoneyIn` mapped to Debit on the linked Asset Account and `MoneyOut` mapped to Credit; and
- an identifiable Expense, Income, Transfer, or Owner Equity source record.

Confirmation revalidates candidacy at confirmation time and persists only a confirmed immutable Match. A Bank Transaction may have at most one confirmed Match at the database boundary.

These exact-candidate rules and supported source types describe current code; they remain Draft until reviewed. §12.2 decides the cardinality of Journal-to-Bank-Transaction matches — one, except a Transfer's two legs — but the suggester does not yet implement the two-leg exception.

## 7. Reconciliation calculation and lifecycle

The current difference calculation is:

`implied closing = opening balance + MoneyIn - MoneyOut`

for every imported Bank Transaction on the selected Bank Account whose transaction date is within the inclusive Reconciliation period. The unexplained difference compares the stated closing balance against this implied closing balance using exact Money.

The current lifecycle is:

`Draft → InReview → Balanced → Completed`

- `markBalanced` rejects any nonzero live difference.
- `complete` recomputes and rejects any nonzero live difference instead of trusting stale `Balanced` state.
- lifecycle transitions lock the Reconciliation row and persist transactionally; genuine two-process races prove that exactly one caller advances each lifecycle edge while the other is safely rejected.
- `reopen` accepts only `Completed`, requires a non-empty reason, returns to `Draft`, clears `completed_at`, and atomically appends a reopening-history record.

§12.1 decides that matching completeness is also a prerequisite for `Completed` (every in-period Bank Transaction must hold a confirmed Match, not only a zero arithmetic difference); this is not yet implemented in `markBalanced`/`complete` today.

## 8. Persistence integrity

The production migration chain now enforces tenant-coherent composite relationships for:

- Import Batch → Bank Account;
- Bank Transaction → Bank Account and its own Import Batch;
- Match → Bank Transaction and Journal;
- Reconciliation → Bank Account; and
- Reconciliation Reopening → Reconciliation.

Invalid pre-existing cross-tenant rows make the hardening migration fail. It never rewrites, reassigns, or silently accepts ambiguous financial data.

The migration chain also enforces the already-approved persistence facts that import counts are non-negative and reconcile exactly, Bank Transaction amounts are non-negative magnitudes, Banking currency is MYR, `Completed` and `completed_at` are mutually consistent, and reopening reasons are non-blank. §12.9 decides that a negative statement or reconciliation *balance* is out of scope and must fail closed at import time; this fail-closed validation is not yet implemented at that boundary — today's code only fails later, implicitly, when difference computation needs a negative intermediate value.

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
- `BNK-014` — A Transfer Journal has at most two confirmed Matches, one per leg, each against the Bank Account corresponding to that leg's own Account; every other Journal type retains at most one (§12.2).
- `BNK-015` — A Reconciliation's period never overlaps another Reconciliation's period for the same Bank Account, in any lifecycle state (§12.4).
- `BNK-016` — Completion requires exact zero arithmetic difference **and** a confirmed Match for every in-period Bank Transaction (§12.1).
- `BNK-017` — A Completed Reconciliation's verified result is an immutable snapshot; a later import never silently alters it, and re-inclusion requires an explicit `reopen()` (§12.3).
- `BNK-018` — Concurrent identical import and Match-confirmation races resolve as deterministic replay or an explicit typed conflict, never a raw persistence error or a silent duplicate effect (§12.6).
- `BNK-019` — Every Match's confidence is the discrete value `Exact`; no calibrated numeric score is claimed by the currently supported matcher (§12.7).
- `BNK-020` — Import or reconciliation of any Bank Account or period containing a negative balance fails closed with an explicit validation error at import time (§12.9).

These eight join `BNK-001`–`BNK-013` as candidate invariants pending the same ATS-008 traceability and Accounting Domain Reviewer sign-off (§13); none is implemented by this document alone.

## 10. Failure semantics

- Every Banking route requires authenticated, tenant-resolved access.
- Malformed route identifiers and invalid write payloads return validation failure without persistence; canonical identifiers that are absent or belong to another Tenant fail closed as not found where the route addresses a resource directly.
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
- Implementation and real-PostgreSQL proof of `BNK-014`–`BNK-020` — done as of 2026-09-16 (`e1e4ae5`, `3d83477`, `b25590b`, `d0c98b1`, `084b2e7`): two-leg Transfer matching, period-overlap rejection, full-matching completion prerequisite, the immutable completion snapshot (with its own migration/FK proof), replay/conflict concurrency contracts for both import and match confirmation (each with a genuine two-process race proof), the discrete `Exact` confidence value (with its own migration/CHECK-constraint proof), and fail-closed negative-balance validation. Full regression suite run four consecutive times clean and deterministic after the last of these landed.
- As of 2026-09-19, the connected bank-statement segment named above now exists: AETS-012's `hore-my-poa-v1` v1.0.0 candidate Golden Dataset includes a real bank statement CSV import → matching → a completed Reconciliation at exact RM0.00 difference for two Bank Accounts, executed through the real `BankStatementImportService`/`MatchingService`/`ReconciliationService` and proven against real PostgreSQL by `tests/Feature/ProofOfAccuracy/ProofOfAccuracyCertificationTest.php` (see AETS-012 §10, ATS-012 §5.2). This is a **candidate** segment only — the dataset carries an explicit CTO-proposal marker and is not yet Accounting Domain Reviewer-approved, so it does not itself satisfy this bullet's "approved Proof of Accuracy golden dataset" wording; it removes the *implementation* gap and leaves only the review gap, which is the same outstanding Accounting Domain Reviewer sign-off already named below.
- As of 2026-09-19, ATS-008's own full normative traceability (`BNK-001`–`BNK-020` each mapped to its executable evidence, ATS-008 §2) is complete and found two genuine gaps not previously named as required evidence: `BNK-001` (import has zero ledger effect) and `BNK-013` (AI/matching cannot post or bypass Accounting Core) have no dedicated test — both are architecturally plausible but unproven, tracked as `BNK-T059`/`BNK-T060` (ATS-008 §4).
- Remaining before Activation: Accounting Domain Reviewer sign-off on both this document and the golden dataset's canonical facts/expected values (still outstanding, see §13), and closing `BNK-T059`/`BNK-T060`.

## 12. Founder/Product decisions (2026-09-16)

Every decision below was made by the CTO under explicit, dated Founder delegation ("aku serahkan keputusan ini pada kau, decide yang paling terbaik untuk release dan jangka panjang" — 2026-09-16), optimizing for release safety and long-term architectural coherence over short-term feature breadth. This section records what was decided and why. None of it is implemented by this edit alone — §11 and §13 still gate Activation on building, testing, and proving each decision, and on a qualified Accounting Domain Reviewer's independent sign-off on accounting correctness.

### 12.1 Completion prerequisite — decided: full matching required

`complete()` requires exact RM0.00 arithmetic difference **and** a confirmed Match for every in-period Bank Transaction. Arithmetic-zero alone permits offsetting errors (two wrong, canceling mismatches) to pass as a clean reconciliation; requiring every line matched is what real double-entry reconciliation practice (and this project's own exact-matching, no-fuzzy-shortcuts posture) means by "reconciled." A Bank Transaction with no corresponding accounting record is a signal to post the missing Expense/Income/Transfer/Owner-Equity entry first — reconciliation is deliberately built as the process that surfaces unrecorded activity, not one that tolerates it silently. See `BNK-016`.

### 12.2 Match cardinality and split/transfer behaviour — decided: two-leg Transfers are the one exception

Every Journal type keeps at most one confirmed Match — except a Transfer Journal, which may have at most two: one per leg, each against a distinct Bank Transaction on the Bank Account that corresponds to that leg's own source or destination Account. A Transfer between two of the Tenant's own onboarded Bank Accounts genuinely produces two bank-statement rows (money leaving one, arriving at the other) for one accounting event; the matching suggester must stop excluding an already-matched Journal outright and instead allow exactly the second, opposite-Bank-Account leg. See `BNK-014`.

### 12.3 Late imports affecting a Completed Reconciliation — decided: immutable completion snapshot, never silent drift

`complete()` durably records the exact set of Bank Transactions (or an equivalent deterministic aggregate) it verified as zero-difference, at the instant of completion. A Bank Transaction imported afterward — even one whose Transaction Date falls inside an already-completed period — is never silently absorbed into that stored result; a `Completed` Reconciliation's recorded outcome does not change without a human action. The late Bank Transaction is instead surfaced as unreconciled, and reusing this project's own already-built, already-audited `reopen()` flow (§7) is the only path to incorporate it. This was chosen over blocking the late import outright because banks do post backdated value dates in practice, and over silent live recomputation because a `Completed` reconciliation that can quietly stop being true defeats its entire purpose as a trusted, signed-off artifact. See `BNK-017`.

### 12.4 Overlapping Reconciliations — decided: no overlap, ever, per Bank Account

A new Reconciliation's period must not overlap, in whole or in part, any existing Reconciliation's period for the same Bank Account, regardless of that existing Reconciliation's lifecycle state — including `Completed` and a `Completed` Reconciliation currently sitting reopened back in `Draft`. Real bank statements arrive as an ordered, non-overlapping sequence of periods; allowing overlap would make "which Reconciliation does this Bank Transaction's difference belong to" ambiguous and would undermine the immutable-snapshot guarantee just decided in §12.3. Enforced at both the application boundary and, matching this project's established double-layer integrity pattern, a database constraint. See `BNK-015`.

### 12.5 Guided file mapping and parser variants — decided: out of MVP scope

The canonical single CSV layout (§5) remains the complete supported import contract for this document's Active version. SRS BNK-002's guided-mapping aspiration is real but is a substantial, independent product surface (accepted-format inventory, mapping-persistence UX, validation flow) that does not gate this release. This mirrors the Master Context's own precedent of explicitly excluding live bank feeds from MVP (§2.2): a scoped boundary, not a temporary gap, to be picked up by a dedicated future minor version or successor document.

### 12.6 Concurrency outcome contracts — decided: deterministic replay, or an explicit typed conflict

Concurrent identical-file import races resolve as deterministic replay: a second caller submitting the same file hash against the same Bank Account while the first is still in flight receives the same `ImportBatch` result as the first, never an error — the database-level proof already committed for this (`f7f05c9`) is ratified as the caller-visible contract, not merely an internal safety property. Concurrent Match confirmations against the same Bank Transaction resolve the same way this codebase already resolves Task-submission races (WTS-001 `TSK-011`): confirming the same candidate Journal again replays the existing confirmed Match; confirming a different candidate Journal against a Bank Transaction a concurrent winner already confirmed returns an explicit, typed conflict — never a raw database exception, never a silent second Match. See `BNK-018`.

### 12.7 Matching score and rationale — decided: a discrete Exact confidence, not a calibrated number

The only score this document's supported matcher may claim is a discrete `MatchConfidence::Exact` value, since only exact-criterion matching (§6) is in scope. A calibrated, weighted, or probability-based score — and the thresholds and human-review rules a real fuzzy score would require — stay explicitly out of scope, consistent with §2.2's existing exclusion of fuzzy matching. This closes the representation ambiguity without building the fuzzy system the SRS aspires to. See `BNK-019`.

### 12.8 Additional match targets and reporting — decided: confirmed deferred, not silently dropped

Invoice/document match targets and the Reconciliation Report (already excluded by §2.2) are confirmed out of this document's Active-version scope. Their eventual contracts must be added as a future addition that does not weaken any invariant in §9 — this is a scope boundary the team is choosing deliberately, not an implementation gap blocking Activation.

### 12.9 Negative statement balances and overdrafts — decided: fail closed, explicitly, at the right boundary

Overdraft and negative statement/running/closing balances are out of scope for this document's Active version. A Bank Account or statement period whose opening balance, closing balance, or any running balance is negative must fail closed with an explicit, user-facing validation error raised at import time — not only implicitly, later, when difference computation happens to need a negative intermediate value, as today. This defers rather than forecloses an eventual signed-balance model: introducing negative Money handling has cross-cutting blast radius across AETS-003's non-negative Money invariant well beyond Banking alone, and a change with that reach must not be decided as a side effect of unblocking Banking. Malaysian solopreneur/microbusiness banking (this product's actual target per the Master Context) rarely operates in genuine sustained overdraft, so this ships the common case now and leaves the harder signed-Money architecture question for its own dedicated ADR if real customer need ever demands it. See `BNK-020`.

## 13. Activation checklist

- [x] CTO / Technical Partner review recorded — reviewed 2026-09-19: `BNK-014`–`BNK-020` implementation re-confirmed against current code, and the connected Proof of Accuracy golden-dataset segment (§11) built and proven end to end against real PostgreSQL.
- [ ] Accounting Domain Reviewer approval recorded — outstanding; distinct from the Founder-delegated CTO decisions in §12, and still required for accounting correctness sign-off. This also covers approving the `hore-my-poa-v1` v1.0.0 dataset's canonical facts and expected values (§11).
- [x] Founder / Product decisions in §12 recorded where required — recorded 2026-09-16 under explicit Founder delegation to the CTO.
- [x] Every accepted decision converted into normative MUST-level language — see §12 and `BNK-014`–`BNK-020`.
- [x] `BNK-014`–`BNK-020` implemented in code and proven against real PostgreSQL (§11) — done 2026-09-16.
- [x] ATS-008 updated from evidence inventory to complete normative traceability — done 2026-09-19 (ATS-008 §2); found two genuine gaps (`BNK-001`, `BNK-013`), tracked as `BNK-T059`/`BNK-T060`, which are themselves now an Activation blocker alongside Accounting Domain Reviewer sign-off.
- [x] All required tests pass against real PostgreSQL with zero skips — 1897 tests, run twice consecutively clean and deterministic (2026-09-19, after the Proof of Accuracy additions).
- [x] AETS/ATS indexes and versions updated — done 2026-09-16.

## Changelog

- **0.6.0 (2026-09-19):** Records that ATS-008's full normative traceability is complete (ATS-008 v0.7.0, §2) — closing §13's remaining unchecked item. Two genuine gaps found in the process, `BNK-001` and `BNK-013`, are now named in §11 and tracked as `BNK-T059`/`BNK-T060`, joining Accounting Domain Reviewer sign-off as Activation blockers. No `BNK-NNN` invariant's meaning changed.
- **0.5.0 (2026-09-19):** Records that the connected bank-statement segment §11 names is now implemented and proven — see §11 and §13's updated CTO review line. Still Draft: Accounting Domain Reviewer approval of both this document and the golden dataset's canonical facts/expected values remains outstanding.
- **0.4.0 (2026-09-16):** Adds §5.1: XLSX is now a second accepted file format for the identical fixed v1 import schema §5 already describes (never a second schema, and never SRS BNK-002's own still-deferred guided mapping — `BNK-T053` unchanged). `CsvBankStatementParser` and the new `XlsxBankStatementParser` both delegate to one shared `BankStatementRowParser`, so the two formats can never validate a statement differently; two spreadsheet-only cell-type quirks (a native Excel date, a native numeric amount) are normalized before that shared validation runs. Resolves the Founder's own "Import" instruction (paired with AETS-009 §20/AETS-017's "Eksport PDF/XLSX"). No `BNK-NNN` invariant's meaning changed. Classified **MINOR**.
- **0.3.0 (2026-09-16):** Records that `BNK-014`–`BNK-020` (every §12 decision) is now implemented and proven against real PostgreSQL, not merely decided — see §11 and §13. No policy changed from v0.2.0; this only updates implementation status.
- **0.2.0 (2026-09-16):** Resolves every §12 decision group under explicit Founder delegation to the CTO ("aku serahkan keputusan ini pada kau, decide yang paling terbaik untuk release dan jangka panjang") and adds `BNK-014`–`BNK-020` to §9 as the resulting candidate invariants. This records accepted policy; none of it is implemented in code yet — §11 and §13 updated accordingly. A qualified Accounting Domain Reviewer's independent sign-off on accounting correctness remains outstanding and unaffected by this delegation.
- **0.1.3 (2026-09-16):** Records the implemented authenticated HTTP boundary and deterministic malformed/missing identifier semantics across every Banking route. No workflow or accounting policy changed.
- **0.1.2 (2026-09-16):** Records the executable two-process concurrency proof for every fixed Reconciliation lifecycle edge, including atomic reopening history. No caller-visible API policy or unresolved workflow decision changed.
- **0.1.1 (2026-09-15):** Records database enforcement of approved exact-value/state facts and explicitly defers negative bank-balance/overdraft sign semantics. No unresolved policy was selected.
- **0.1.0 (2026-09-15):** Initial Draft. Records locked requirements, current implementation evidence, schema tenant hardening, and eight unresolved decision groups. No new authority, MVP scope, or accuracy claim is introduced.
