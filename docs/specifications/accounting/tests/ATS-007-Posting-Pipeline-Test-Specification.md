# ATS-007: Accounting Commands & Posting Pipeline Test Specification

- Status: Active
- Version: 1.1.0
- Effective date: 2026-09-05
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](../AETS-000.md), [AETS-001](../AETS-001-Accounting-Terminology.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-003](../AETS-003-Money-Specification.md), [AETS-004](../AETS-004-Journal-Posting-Model.md), [AETS-005](../AETS-005-Chart-of-Accounts.md), [AETS-007](../AETS-007-Posting-Command.md); [ADR-0001](../../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../../adr/0005-ai-provider-abstraction.md), [ADR-0006](../../../adr/0006-transactional-outbox-pattern.md)

## 1. Purpose

This document is the normative Accounting Test Specification (ATS) proving compliance with [AETS-007: Accounting Commands & Posting Pipeline](../AETS-007-Posting-Command.md). It exists so Posting Engine implementation work has a precise, testable, traceable target before any code is written — exactly the coverage AETS-007 §24 said a future Posting Command Test Specification must provide.

Every test defined here is identified by a stable ID (`POST-T001`–`POST-T131`) and traced to the `POST-NNN` invariant(s) it proves (§6). This document does not implement any test; it specifies what must be proven, at what level, and with what data, so that an implementer or an automated agent can build the actual test suite against it.

## 2. Scope

### 2.1 In scope

- Test-level classification (unit/structural, integration, architecture/static-analysis, property-based, golden) for every `POST-NNN` invariant ([AETS-007 §23](../AETS-007-Posting-Command.md#23-invariants)) and every MUST-level requirement AETS-007 states.
- A complete traceability matrix from `POST-001`–`POST-027` to test IDs (§6).
- Concrete test cases — including golden scenarios — for Posting Command construction, tenant validation, Idempotency Key and Source Fingerprint handling, Actor/Source/Evidence requirements, Journal input/state validation, Account validation, Money/Currency/balance validation, duplicate prevention, atomic persistence (including fault injection), failure and success semantics, the AI-authority boundary, Audit Event and Outbox atomicity, the no-network-in-transaction architecture rule, and concurrency.
- Generator definitions for property-based testing (§26.1).
- The Posting Command's own contract only — this document proves the same Posting Command AETS-007 defines, it does not extend or narrow that contract.

### 2.2 Out of scope

- Any implementation code, test framework configuration, migration, Posting Engine class, or idempotency/outbox/audit schema — this document specifies tests, it does not build any of them.
- Everything [AETS-007 §2.2](../AETS-007-Posting-Command.md#22-out-of-scope) already excludes: the Journal/Journal Line domain contract itself (fully proven by [ATS-004](ATS-004-Journal-Posting-Test-Specification.md)), the Account entity itself (fully proven by [ATS-005](ATS-005-Chart-of-Accounts-Test-Specification.md)), the Money domain contract itself (fully proven by [ATS-003](ATS-003-Money-Test-Specification.md)), Invoice/Expense/Bank-Reconciliation/MyInvois/tax/reporting/default-Chart-of-Accounts/Account-Code-numbering/UI/AI-prompt-design, and every business-specific Accounting Command other than the Posting Command (invoicing, payment allocation, and so on — deferred future subsections of AETS-007 itself, per its own §1/§2.2/§26).
- Detailed correction-workflow mechanics (AETS-006, Posting Rules) — this document does not test Reversal/Replacement approval or frequency policy, only that a Reversal/Replacement is posted through the ordinary pipeline like any other Journal, exactly as [ATS-004](ATS-004-Journal-Posting-Test-Specification.md) already proves for the Journal side of that guarantee.
- Concrete Idempotency Key or Source Fingerprint derivation algorithms — [AETS-007 §6](../AETS-007-Posting-Command.md#6-command-identity-and-idempotency) states the contract only; this document tests the contract, not a specific hashing scheme (§29).
- Concrete Actor, Source, Evidence, or Audit Event object schemas — [AETS-007 §2.2](../AETS-007-Posting-Command.md#22-out-of-scope) defers these; this document tests only the Posting Command's *relationship* to each, exactly as AETS-007 itself does.
- Behavior AETS-007 does not define. Where this task's required test list touches something AETS-007 leaves ambiguous, this document flags it inline rather than inventing an answer.

## 3. Authority

This document is subordinate to [AETS-007](../AETS-007-Posting-Command.md), which is itself subordinate to [AETS-004](../AETS-004-Journal-Posting-Model.md), [AETS-005](../AETS-005-Chart-of-Accounts.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-003](../AETS-003-Money-Specification.md), and the authority hierarchy in [AETS-000 §3](../AETS-000.md#3-authority-hierarchy). Where a test specified here appears to require behavior AETS-007 does not define, that is a defect in this document to be corrected, not a license to invent AETS-007 content through a test (per [AETS-000 §8.4](../AETS-000.md#84-relationship-to-adrs-and-conflict-handling)). No contradiction between this document and AETS-007 was found while drafting it, so AETS-007 was not modified.

## 4. Test strategy

- **Prove the invariant, not the implementation.** Every test traces to a `POST-NNN` invariant or an explicit AETS-007 requirement — not to incidental implementation detail, mirroring [ATS-004 §4](ATS-004-Journal-Posting-Test-Specification.md#4-test-strategy)'s already-established approach for this series.
- **Exact-or-fail, tested both ways.** Per [AETS-007 §18–§19](../AETS-007-Posting-Command.md#18-failure-semantics), every Posting Command must produce exactly one of: a newly Posted Journal, an idempotent replay result, or a typed failure. This ATS tests every reachable outcome explicitly.
- **No silent behavior is acceptable evidence.** A test that merely checks "no exception was thrown" is insufficient; tests assert the specific expected terminal result or the specific expected failure category.
- **Failure-before-side-effect is provable, not assumed.** Any test claiming "rejected before any persistent effect" must verify the absence of a row/record afterward, not merely catch an exception (§19, §28).
- **Don't invent AETS-007 content.** Where AETS-007 leaves a case ambiguous or explicitly deferred (§26 of that document), this document says so rather than picking an answer a test would then silently enforce.
- **Real PostgreSQL for real guarantees.** Atomicity, concurrency, idempotency, and duplicate-prevention claims are proven against a real PostgreSQL instance where a unit-level double cannot prove them (§28) — never silently substituted with SQLite, consistent with the precedent already established across [ATS-003](ATS-003-Money-Test-Specification.md), [ATS-004](ATS-004-Journal-Posting-Test-Specification.md), and [ATS-005](ATS-005-Chart-of-Accounts-Test-Specification.md).
- **Idempotency Key and Source Fingerprint are distinct, both tested in full.** Neither substitutes for the other in this document's test design, exactly as [AETS-007 §6](../AETS-007-Posting-Command.md#6-command-identity-and-idempotency) requires — a test proving one does not stand in for proving the other.

| Level | Purpose | Primary sections |
| --- | --- | --- |
| **Structural / construction** | Posting Command shape, required-field presence, type-level distinctions. | §7 |
| **Validation** | Tenant, Idempotency Key, Source Fingerprint, Actor/Source, Evidence, Journal input/state, Account, Money/Currency/balance. | §8–§16 |
| **Duplicate prevention / atomicity** | Duplicate-command and duplicate-source guarantees; transaction boundary, including fault injection. | §17–§18 |
| **Failure / success semantics** | Outcome completeness and category distinguishability. | §19–§20 |
| **AI-authority boundary** | Validation parity, no-bypass, no-fabrication. | §21 |
| **Auditability** | Audit Event and Outbox atomicity; no-network-in-transaction architecture proof. | §22–§24 |
| **Concurrency** | Real, genuinely concurrent PostgreSQL connections. | §25 |
| **Property-based** | Behavior proven across a generated range of inputs, not fixed examples. | §26 |
| **Golden scenarios** | Fixed, exact, hand-verified Posting Command examples that must never silently change. | §27 |
| **Real PostgreSQL integration** | The dedicated, real-database proof of every atomicity/concurrency/idempotency/duplicate-prevention claim above. | §28 |

## 5. Test environment

- **Abstract identifiers only.** Account, Tenant, Actor, Idempotency Key, and Source Fingerprint values used throughout this document are synthetic placeholders (`ACCOUNT-CASH`, `TENANT-A`, `ACTOR-1`, `K1`, `FP1`, and so on) — never a real Chart of Accounts code ([AETS-005 §2.2](../AETS-005-Chart-of-Accounts.md#22-out-of-scope)) and never real business data.
- **MYR only.** All test data uses MYR at scale 2, consistent with MVP scope ([`HORE_MY_MASTER_CONTEXT.md`](../../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9), reusing [ATS-003](ATS-003-Money-Test-Specification.md#19-golden-test-cases)'s and [ATS-004](ATS-004-Journal-Posting-Test-Specification.md#19-golden-test-cases)'s existing golden MYR values where convenient.
- **Two-Tenant minimum for isolation tests.** Every tenant-isolation test (§8) requires at least two distinct, otherwise-valid Tenants, each with its own seeded Accounts, so a cross-tenant reference is genuinely cross-tenant, not merely a missing value — mirroring [ATS-004](ATS-004-Journal-Posting-Test-Specification.md#6-test-data-strategy)'s and [ATS-005](ATS-005-Chart-of-Accounts-Test-Specification.md)'s own established convention.
- **Seeded Account fixtures.** Account validation tests (§14) require, at minimum, one Active/posting-eligible Account, one Inactive Account, one non-posting/group Account, and one Account belonging to a different Tenant — reusing [ATS-005](ATS-005-Chart-of-Accounts-Test-Specification.md)'s own Account construction helpers rather than re-deriving Account behavior here.
- **Real PostgreSQL for integration/concurrency/atomicity evidence.** Tests in §18 (atomic persistence, fault injection), §25 (concurrency), and §28 (integration) require the project's own Docker Postgres service ([`docker-compose.yml`](../../../../docker-compose.yml)), not SQLite. A unit-level double may simulate the same scenario for fast feedback, but does not substitute for the real-database proof, exactly as [ATS-004 §6](ATS-004-Journal-Posting-Test-Specification.md#6-test-data-strategy) already establishes for this series.
- **Fault-injection points.** Atomic-persistence tests (§18) require the ability to force a failure after the Journal header write, after some but not all Journal Lines, during Evidence-linkage write, during Audit Event write, and during Outbox-event write — the specific injection mechanism (a real, unavoidable constraint violation where one exists, or a controlled test-only failure point otherwise) is an implementation detail this document does not design, mirroring the precedent already established for `JournalRepositoryIntegrationTest`'s own atomic-rollback proof (reusing a real foreign-key constraint rather than inventing one).
- **Two independent PostgreSQL connections for concurrency.** Every concurrency test (§25) requires two genuinely independent database connections, never two calls on one connection or an in-process simulation — mirroring the technique already established and proven in `AccountRepositoryIntegrationTest`/`JournalRepositoryIntegrationTest` (a cloned `pgsql_secondary` connection with a short `lock_timeout`).
- **Property-based generators** are defined in §26.1. The property-based testing library itself remains undecided project-wide ([ATS-003 §23](ATS-003-Money-Test-Specification.md#23-deferred-tests), [ATS-004 §23](ATS-004-Journal-Posting-Test-Specification.md#23-deferred-tests)) — this document specifies the required properties and generators only, not the tooling (§29).

## 6. POST invariant traceability matrix

Every `POST-NNN` invariant from [AETS-007 §23](../AETS-007-Posting-Command.md#23-invariants) maps to at least one test ID below.

| Invariant | Summary | Test IDs |
| --- | --- | --- |
| POST-001 | Tenant ownership validated at acceptance | POST-T011, POST-T012, POST-T013, POST-T014, POST-T015, POST-T016, POST-T104 |
| POST-002 | Command identity required | POST-T002, POST-T003, POST-T004, POST-T005 |
| POST-003 | Idempotent accounting effect | POST-T018, POST-T019, POST-T085, POST-T112, POST-T128 |
| POST-004 | Conflicting idempotency reuse rejected | POST-T021, POST-T105, POST-T129 |
| POST-005 | Valid Actor and Source required | POST-T032, POST-T034 |
| POST-006 | AI cannot be recorded as Actor | POST-T033, POST-T089 |
| POST-007 | Evidence traceability where applicable | POST-T037, POST-T038, POST-T039 |
| POST-008 | Account existence required | POST-T046, POST-T047 |
| POST-009 | Account same-Tenant ownership | POST-T014, POST-T048 |
| POST-010 | Account must be Active | POST-T049 |
| POST-011 | Account must be posting-eligible | POST-T050, POST-T051 |
| POST-012 | Minimum Journal Line count | POST-T061, POST-T062 |
| POST-013 | Single Currency per Journal | POST-T058 |
| POST-014 | Non-negative Money magnitude | POST-T055 |
| POST-015 | Explicit Direction required | POST-T056, POST-T057 |
| POST-016 | Exact Debit == Credit | POST-T063, POST-T064 |
| POST-017 | No binary float anywhere in the pipeline | POST-T054, POST-T110 |
| POST-018 | Draft-only candidate input | POST-T043, POST-T044 |
| POST-019 | Atomic posting | POST-T070, POST-T127 |
| POST-020 | Rollback on failure | POST-T071, POST-T072, POST-T073, POST-T074, POST-T075, POST-T111, POST-T127 |
| POST-021 | One authoritative Posted Journal on success | POST-T065, POST-T081 |
| POST-022 | No network call inside the transaction | POST-T099, POST-T100, POST-T101, POST-T102 |
| POST-023 | No direct AI posting authority | POST-T086, POST-T087, POST-T088, POST-T092 |
| POST-024 | Audit traceability | POST-T093, POST-T094, POST-T095 |
| POST-025 | Outbox consistency | POST-T096, POST-T097, POST-T098 |
| POST-026 | Required Source Fingerprint fails safely when missing | POST-T028, POST-T130 |
| POST-027 | No fabricated Source Fingerprint | POST-T029, POST-T030 |

## 7. Posting Command construction tests

Structural coverage of the Posting Command's own shape ([AETS-007 §4–§5](../AETS-007-Posting-Command.md#4-posting-command-definition)) — no persistence, no pipeline execution.

| ID | Test |
| --- | --- |
| POST-T001 | A Posting Command with every required field present (Idempotency Key, TenantId, Actor, Source, proposed Journal identity, proposed Journal Lines) is well-formed. |
| POST-T002 | A Posting Command with no Idempotency Key is not well-formed and is rejected before any pipeline step runs (`POST-002`). |
| POST-T003 | A Posting Command with no TenantId is not well-formed and is rejected before any pipeline step runs (`POST-002`). |
| POST-T004 | A Posting Command with no Actor is not well-formed and is rejected before any pipeline step runs (`POST-002`, `POST-005`). |
| POST-T005 | A Posting Command with no Source is not well-formed and is rejected before any pipeline step runs (`POST-002`, `POST-005`). |
| POST-T006 | A Posting Command for a non-evidence-backed effect carries no Evidence reference and is accepted — Evidence is required only where applicable, never fabricated to fill the field ([AETS-007 §10](../AETS-007-Posting-Command.md#10-evidence-references)). |
| POST-T007 | A Posting Command's proposed Journal identity may be a fresh, not-yet-persisted identifier. |
| POST-T008 | A Posting Command's proposed Journal identity may instead be a reference to an existing Draft Journal's identifier ([AETS-007 §11](../AETS-007-Posting-Command.md#11-journal-payload)). |
| POST-T009 | A Posting Command is not itself a Journal — it has no ledger effect merely by existing, and constructing one performs no persistence. |
| POST-T010 | A Posting Command is not itself an Accounting Proposal — a candidate proposal with no confirming Actor cannot be submitted as a Posting Command (§21 covers the AI-specific case in full). |

## 8. Tenant validation tests

Proving [AETS-007 §7](../AETS-007-Posting-Command.md#7-tenant-ownership)'s single tenant-ownership gate against every fact it must cover.

| ID | Test |
| --- | --- |
| POST-T011 | A Posting Command whose own TenantId does not match its referenced Account's Tenant is rejected before any persistent effect (`POST-001`). |
| POST-T012 | A Posting Command whose Actor belongs to a different Tenant than the command is rejected before any persistent effect (`POST-001`). |
| POST-T013 | A Posting Command whose Evidence reference belongs to a different Tenant than the command is rejected before any persistent effect (`POST-001`). |
| POST-T014 | A Posting Command referencing an existing Draft Journal belonging to a different Tenant is rejected before any persistent effect (`POST-001`, `POST-009`). |
| POST-T015 | Every Tenant-ownership rejection above leaves zero rows in every affected table — no partial Journal, Line, Evidence-linkage, Audit, or Outbox record. |
| POST-T016 | A Posting Command where the command, every referenced Account, the Actor, any Evidence, and any referenced Draft Journal all belong to the same Tenant passes the tenant-ownership step. |

## 9. Idempotency Key tests

Proving [AETS-007 §6.1](../AETS-007-Posting-Command.md#61-idempotency-key-universal)'s universal, unconditional contract.

| ID | Test |
| --- | --- |
| POST-T017 | A well-formed Posting Command's first attempt, under a fresh Idempotency Key, succeeds and posts a Journal. |
| POST-T018 | An exact retry (same Tenant, same Idempotency Key, same logical request) of an already-processed command does not create a second Journal or any second accounting effect (`POST-003`). |
| POST-T019 | An exact retry returns a deterministic terminal result identifying the original Journal — not a fresh success indistinguishable from the first (`POST-003`, §20). |
| POST-T020 | Two exact retries of the same already-processed command, submitted sequentially, both return the identical terminal result. |
| POST-T021 | The same Idempotency Key, for the same Tenant, reused with a materially different logical request (different proposed Journal Lines, different Account references, or a different amount) is rejected loudly and distinguishably from both a fresh success and an idempotent replay (`POST-004`). |
| POST-T022 | A conflicting-reuse rejection (POST-T021) leaves the original Journal from the first use of that Idempotency Key completely unchanged. |
| POST-T023 | A different Idempotency Key, for the same Tenant, with an otherwise identical logical request, is treated as an entirely new command — not a conflict, not a replay. |
| POST-T024 | The same Idempotency Key used by two different Tenants produces two independent Journals — Idempotency Key scope is always (Tenant, Key), never Key alone. |

## 10. Source Fingerprint tests

Proving [AETS-007 §6.2](../AETS-007-Posting-Command.md#62-source-fingerprint-conditional)'s conditional contract — distinct from, and never a substitute for, §9's Idempotency Key contract.

| ID | Test |
| --- | --- |
| POST-T025 | A purely manual Posting Command, authored directly by an Actor with no external source material behind it, carries no Source Fingerprint and is accepted. |
| POST-T026 | A Posting Command materially derived from external/imported source data (a Bank Transaction, an uploaded receipt, an uploaded invoice, an imported statement row, or an external integration payload) carries a valid Source Fingerprint and is accepted. |
| POST-T027 | Each of the five source-data examples in POST-T026 is tested independently — no example is assumed to generalize from another. |
| POST-T028 | A Posting Command materially derived from external/imported source data, but missing its required Source Fingerprint, is rejected before any persistent accounting effect (`POST-026`). |
| POST-T029 | No code path accepts a caller-fabricated Source Fingerprint for a command with no external source data behind it, merely to satisfy the field (`POST-027`). |
| POST-T030 | *(Architecture)* No code path in the Posting Command intake or validation pipeline generates a Source Fingerprint value itself for a manual command, to paper over POST-T029's requirement (`POST-027`). |
| POST-T031 | Two Posting Commands carrying the same Source Fingerprint, for the same Tenant, do not both give rise to a Posted Journal — the second is rejected as a duplicate-source attempt, distinct from an Idempotency-Key-based conflicting-reuse rejection (§17). |

## 11. Actor/Source tests

| ID | Test |
| --- | --- |
| POST-T032 | A Posting Command with a valid, identified Actor passes the Actor-presence check (`POST-005`). |
| POST-T033 | AI, or any other proposal-producing process, is never recorded as the Actor accepting a Posting Command (`POST-006`; full AI-boundary coverage in §21). |
| POST-T034 | A Posting Command with a valid Source reference (an Accounting Command, an accepted Accounting Proposal, or a correction reference) passes the Source-presence check (`POST-005`). |
| POST-T035 | The Journal a successful Posting Command produces retains the exact Source reference the command carried. |
| POST-T036 | Actor and Source are recorded independently — a command's Actor and its Source answer different questions and neither is derived from the other ([AETS-007 §9](../AETS-007-Posting-Command.md#9-source)). |

## 12. Evidence-reference tests

| ID | Test |
| --- | --- |
| POST-T037 | A Posting Command for an evidence-backed effect that omits its Evidence reference is rejected (`POST-007`). |
| POST-T038 | A Posting Command for an evidence-backed effect that carries its Evidence reference posts successfully, with the Evidence linkage present on the resulting Journal, committed atomically (`POST-007`; atomicity proof in §22). |
| POST-T039 | A Posting Command with no independent evidence of its own (for example, a pure Reversal, whose evidentiary basis is the original Journal it references) is not required to fabricate one, and is not rejected for omitting it (`POST-007`). |
| POST-T040 | AI never supplies a fabricated Evidence reference to satisfy POST-T037 (full coverage in §21). |

## 13. Journal input/state tests

| ID | Test |
| --- | --- |
| POST-T041 | A Posting Command proposing a fresh Journal (no existing identity referenced) is accepted, subject to the rest of the pipeline. |
| POST-T042 | A Posting Command referencing an existing Draft Journal by identity is accepted, subject to the rest of the pipeline. |
| POST-T043 | A Posting Command referencing an existing Journal whose recorded state is already Posted is rejected — Draft-only candidate input (`POST-018`). |
| POST-T044 | A Posting Command referencing a Journal identity that does not resolve to any existing Journal, and that is not accompanied by a complete fresh Journal Line set, is rejected as malformed input. |
| POST-T045 | Journal Line order, where the command supplies it, is preserved unchanged through every validation step. |

## 14. Account validation tests

Proving the four-step sequence [AETS-007 §12](../AETS-007-Posting-Command.md#12-account-validation) reuses from [AETS-005 §19](../AETS-005-Chart-of-Accounts.md#19-journal-line-integration) exactly.

| ID | Test |
| --- | --- |
| POST-T046 | A Posting Command whose every referenced Account exists, belongs to the command's Tenant, is Active, and is posting-eligible passes Account validation. |
| POST-T047 | A Posting Command referencing an Account identifier that does not resolve to any existing Account is rejected (`POST-008`). |
| POST-T048 | A Posting Command referencing an Account belonging to a different Tenant than the command is rejected (`POST-009`; see also §8). |
| POST-T049 | A Posting Command referencing a currently Inactive Account is rejected (`POST-010`). |
| POST-T050 | A Posting Command referencing a currently non-posting-eligible Account is rejected (`POST-011`). |
| POST-T051 | A Posting Command referencing a non-posting/group Account is rejected regardless of that Account's Active/Inactive state (`POST-011`; restates `COA-010` at the command level). |
| POST-T052 | A Posting Command with two proposed lines, one referencing a valid Account and the other an invalid one (missing, wrong-Tenant, Inactive, or non-posting-eligible), is rejected in full — the valid line is never partially accepted. |
| POST-T053 | *(Architecture)* No code path in Account validation consults an Account's Normal Balance to infer, default, or validate a Journal Line's Direction — Direction is accepted exactly as the command supplies it ([AETS-007 §12](../AETS-007-Posting-Command.md#12-account-validation), [AETS-005 §11](../AETS-005-Chart-of-Accounts.md#11-normal-balance)). |

## 15. Money/currency tests

Proving [AETS-007 §13](../AETS-007-Posting-Command.md#13-money-validation)'s Money-level intake rules.

| ID | Test |
| --- | --- |
| POST-T054 | A Posting Command carrying a native binary-float monetary value is rejected at the type level, before any grammar or value validation runs (`POST-017`). |
| POST-T055 | A Posting Command carrying a negative Money magnitude for any proposed line is rejected (`POST-014`). |
| POST-T056 | A Posting Command carrying a proposed line with no explicit Direction is rejected (`POST-015`). |
| POST-T057 | A Posting Command carrying a proposed line whose Direction is neither exactly Debit nor exactly Credit (a malformed or dual value) is rejected (`POST-015`). |
| POST-T058 | A Posting Command whose proposed Journal Lines do not all share exactly one Currency is rejected (`POST-013`). |
| POST-T059 | A Posting Command carrying a malformed decimal Money value (not conforming to the canonical grammar, or exceeding the target Currency's scale) is rejected. |
| POST-T060 | A Posting Command carrying a zero-amount proposed line is accepted — AETS-007 does not prohibit one, and no minimum monetary value is invented ([AETS-004 §7](../AETS-004-Journal-Posting-Model.md#7-journal-line)). |

## 16. Journal structure and balance tests

| ID | Test |
| --- | --- |
| POST-T061 | A Posting Command proposing fewer than two Journal Lines is rejected (`POST-012`). |
| POST-T062 | A Posting Command proposing exactly two Journal Lines is accepted, subject to the rest of the pipeline — the minimum is satisfied (`POST-012`). |
| POST-T063 | A Posting Command whose proposed Journal's total Debit Money exactly equals total Credit Money posts successfully (`POST-016`). |
| POST-T064 | A Posting Command whose proposed Journal's total Debit Money does not exactly equal total Credit Money — including an imbalance of exactly one minor unit — is rejected, with zero tolerance (`POST-016`). |
| POST-T065 | A successful Posting Command results in exactly one Posted Journal — never zero, never two (`POST-021`; full success-semantics coverage in §20). |

## 17. Duplicate-prevention tests

Proving [AETS-007 §15](../AETS-007-Posting-Command.md#15-duplicate-prevention)'s two-point guarantee, and its distinctness from Source Fingerprint's own duplicate-source guarantee (§10).

| ID | Test |
| --- | --- |
| POST-T066 | Step 2's logical idempotency check runs, and short-circuits the remaining pipeline steps, before any Account/Money/balance validation executes for an already-processed (Tenant, Idempotency Key) pair. |
| POST-T067 | *(Architecture)* Duplicate prevention does not rely on an application-level "check, then act" sequence alone — a database-level uniqueness constraint on (Tenant, Idempotency Key) is present and is the mechanism a race ultimately resolves through (real-PostgreSQL proof in §25/§28). |
| POST-T068 | A Source Fingerprint's own uniqueness constraint (§10, POST-T031) operates independently of the Idempotency Key uniqueness constraint — a source-duplicate rejection and an idempotency-conflict rejection are distinguishable failure categories (§19). |
| POST-T069 | The (Tenant, Idempotency Key) → Journal association is recorded durably as part of the same atomic transaction that posts the Journal — never as a separate, later write (§18; restates the Posting-Command-level requirement [AETS-004 §14](../AETS-004-Journal-Posting-Model.md#14-idempotency) already states for Journal). |

## 18. Atomic persistence tests

Proving [AETS-007 §17](../AETS-007-Posting-Command.md#17-atomic-transaction-requirements)'s transaction boundary — real PostgreSQL required throughout (§5, §28).

| ID | Test |
| --- | --- |
| POST-T070 | A successful Posting Command commits the Journal header, every Journal Line, required Evidence linkage, the Audit Event, and any required Outbox event together, in one transaction — all present immediately after commit (`POST-019`). |
| POST-T071 | A fault injected immediately after the Journal header write, before any Journal Line write, rolls back the entire transaction — zero Journal row afterward (`POST-020`). |
| POST-T072 | A fault injected after some, but not all, required Journal Line writes rolls back the entire transaction — zero Journal and zero Line rows afterward, not a partial line set (`POST-020`). |
| POST-T073 | A fault injected during the Evidence-linkage write rolls back the entire transaction — zero Journal, Line, and Evidence-linkage rows afterward (`POST-020`). |
| POST-T074 | A fault injected during the Audit Event write rolls back the entire transaction — zero Journal, Line, Evidence-linkage, and Audit Event rows afterward (`POST-020`). |
| POST-T075 | A fault injected during the required Outbox-event write rolls back the entire transaction — zero Journal, Line, Evidence-linkage, Audit Event, and Outbox rows afterward (`POST-020`). |
| POST-T076 | Each fault-injection test (POST-T071–POST-T075) is verified by querying the database directly after the failed attempt, for every one of the five record types, not inferred from a caught exception alone. |

## 19. Failure-semantics tests

| ID | Test |
| --- | --- |
| POST-T077 | Every distinct failure category [AETS-007 §18](../AETS-007-Posting-Command.md#18-failure-semantics) names (malformed command, Tenant-ownership mismatch, conflicting Idempotency Key reuse, missing required Source Fingerprint, non-Draft existing Journal, each Account-resolution failure, invalid Journal Line, unbalanced Journal, persistence/infrastructure failure) is distinguishable by callers — for example, by type — never merged into one generic error. |
| POST-T078 | Every validation-pipeline rejection (§8–§17), at any step, produces zero Journal effect and zero partial ledger effect of any kind — no Journal row, no Line row, at any state. |
| POST-T079 | A vendor-library exception encountered anywhere in the Posting Command intake or validation path does not escape as a vendor type — translated per Money's own vendor isolation (`MON-010`), mirroring [ATS-003](ATS-003-Money-Test-Specification.md)'s and [ATS-004 §17](ATS-004-Journal-Posting-Test-Specification.md#17-failure-semantics-tests)'s established pattern. |
| POST-T080 | A persistence/infrastructure failure (for example, a Money value's `BIGINT` bounds) surfaces as its own distinguishable failure category, not merged with a validation-level rejection. |

## 20. Success-semantics tests

Resolving [AETS-004 §14](../AETS-004-Journal-Posting-Model.md#14-idempotency)'s deferred replay indicator, per [AETS-007 §19](../AETS-007-Posting-Command.md#19-success-semantics).

| ID | Test |
| --- | --- |
| POST-T081 | A successful Posting Command's terminal result identifies the Posted Journal by its stable identifier (`POST-021`). |
| POST-T082 | A successful Posting Command's terminal result states the resulting Journal's state is Posted. |
| POST-T083 | A successful Posting Command's terminal result allows the caller to determine this invocation newly posted the Journal (not a replay). |
| POST-T084 | An idempotent-replay terminal result (§9) allows the caller to determine this invocation matched an already-processed command, distinguishable from POST-T083's newly-posted indicator. |
| POST-T085 | The terminal result is deterministic — the same (Tenant, Idempotency Key, logical request) always produces the same describable outcome across repeated invocations (`POST-003`). |

## 21. AI-originated command parity tests

Proving [AETS-007 §20](../AETS-007-Posting-Command.md#20-ai-originated-command-rules)'s explicit restatement of [AETS-000 §5](../AETS-000.md#5-ai-philosophy) and [ADR-0005](../../../adr/0005-ai-provider-abstraction.md) at the Posting Command level.

| ID | Test |
| --- | --- |
| POST-T086 | An AI-originated Accounting Proposal, once explicitly confirmed by an authorized Actor into a Posting Command, is validated through exactly the same pipeline (§8–§17) as a directly human-authored command — no shortcut, no skipped step (`POST-023`). |
| POST-T087 | An unconfirmed AI-originated Accounting Proposal cannot itself post — it has zero ledger effect regardless of its content (`POST-023`). |
| POST-T088 | No automated confidence threshold, however high, bypasses the confirmation step or any pipeline stage for an AI-originated proposal (`POST-023`). |
| POST-T089 | AI, or any other proposal-producing process, is never recorded as the Actor accepting a Posting Command, even when the command's Source traces to that process's proposal (`POST-006`; restates POST-T033 with full pipeline execution). |
| POST-T090 | AI never supplies a fabricated Evidence reference for an AI-originated command — an AI-originated command requiring Evidence is rejected exactly as POST-T037 rejects any other command missing required Evidence. |
| POST-T091 | An AI-originated command that is invalid (fails any validation step §8–§17) cannot post, regardless of the confidence score attached to its originating proposal. |
| POST-T092 | *(Architecture)* No code path allows AI, or any other proposal-producing process, to invoke the domain-level `Journal::post()` operation, or to call `JournalRepository::save()`, directly — every path runs through the full Posting Validation Pipeline first (`POST-023`; mirrors [AETS-007 §16](../AETS-007-Posting-Command.md#16-posting-execution-boundary)). |

## 22. Audit Event atomicity tests

| ID | Test |
| --- | --- |
| POST-T093 | A successful Posting Command's Audit Event is present, committed atomically with the Journal it records, immediately after commit (`POST-024`). |
| POST-T094 | A fault injected during or after the Audit Event write (§18, POST-T074) leaves zero Audit Event rows, together with zero Journal rows — never one without the other. |
| POST-T095 | The Audit Event captures, at minimum, Actor, Tenant, Source, and time. |

## 23. Outbox atomicity tests

| ID | Test |
| --- | --- |
| POST-T096 | Where a successful Posting Command requires an asynchronous or external effect, the corresponding Outbox event is present, committed atomically with the Journal, immediately after commit (`POST-025`). |
| POST-T097 | A fault injected during or after the Outbox-event write (§18, POST-T075) leaves zero Outbox rows, together with zero Journal rows — never one without the other. |
| POST-T098 | *(Architecture)* The Outbox event is never published, dispatched, or otherwise delivered from inside the posting transaction — only written to durable storage within it (§24). |

## 24. No-network-inside-transaction tests

*(Architecture)* — mirroring `JRN-T032`/`JRN-T192`'s already-established technique one layer up, at the Posting Command's own transaction boundary.

| ID | Test |
| --- | --- |
| POST-T099 | No HTTP client call exists anywhere within the posting database transaction's code path (`POST-022`). |
| POST-T100 | No queue-publish call exists anywhere within the posting database transaction's code path (`POST-022`). |
| POST-T101 | No webhook dispatch exists anywhere within the posting database transaction's code path (`POST-022`). |
| POST-T102 | No external SDK/provider client call (for example, a future MyInvois or notification client) exists anywhere within the posting database transaction's code path (`POST-022`). |

## 25. Concurrency tests

Real PostgreSQL required throughout — two genuinely independent connections per test (§5).

| ID | Test |
| --- | --- |
| POST-T103 | Two simultaneous Posting Commands with the same Idempotency Key and Tenant produce exactly one Journal; the command that loses the race detects the conflict and returns the winner's result, never erroring destructively and never creating a second Journal (`POST-003`, `POST-004`). |
| POST-T104 | Two simultaneous Posting Commands with the same Idempotency Key but a materially different logical request (a genuine conflicting-reuse race, not a safe replay race) resolve so that at most one Journal is ever created, and the loser's rejection is the conflicting-reuse category (§9), not a silently accepted divergent command (`POST-001`, `POST-004`). |
| POST-T105 | An existing Draft Journal posted concurrently by two different Posting Commands does not produce two Posted Journals from the same Draft — the losing attempt detects the already-Posted state (`POST-004`, `POST-018`). |
| POST-T106 | An Account referenced by an in-flight Posting Command that is deactivated by a concurrent, unrelated operation part-way through does not leave the Posting Command's own outcome incoherent — it either completes validating against the Account state it observed, or is rejected cleanly; no partially-applied Journal results either way. |
| POST-T107 | Two concurrent, unrelated Posting Commands (different Idempotency Keys, potentially referencing the same Account) both succeed independently and correctly, with no lost update and no observable partially-posted state from either. |

## 26. Property-based tests

### 26.1 Generators

| Generator | Produces |
| --- | --- |
| **Well-formed Posting Commands** | A valid Tenant, Actor, Source, fresh Idempotency Key, and a balanced, generated Journal Line set — reusing [ATS-004 §18.1](ATS-004-Journal-Posting-Test-Specification.md#181-generators)'s Balanced Journal Line set generator as the Money/Direction source. |
| **Unbalanced Posting Commands** | The same shape, deliberately perturbed by one minor unit or more so total Debit never equals total Credit. |
| **Retried Posting Commands** | A single generated well-formed command, replayed a randomized number of times, including concurrently, with the same Idempotency Key. |
| **Failing Posting Commands** | Generated commands deliberately violating exactly one validation step (§8–§16) at a time: wrong Tenant, missing/Inactive/non-posting Account, malformed Money, missing required Source Fingerprint, or unbalanced total. |
| **Source-derived Posting Commands** | Well-formed commands additionally carrying a generated Source Fingerprint, paired with a flag marking whether the scenario is genuinely external-source-derived (fingerprint required) or manual (fingerprint absent). |

### 26.2 Properties

| ID | Property |
| --- | --- |
| POST-T108 | For any generated well-formed, balanced Posting Command: posting always succeeds, and the resulting Journal is always found balanced afterward. |
| POST-T109 | For any generated unbalanced Posting Command: posting always fails, and never produces a Journal. |
| POST-T110 | Across every generator category in §26.1, no generated Posting Command construction, validation, or posting attempt ever causes a native binary float to appear in Posting Command state or output (`POST-017`). |
| POST-T111 | For any generated Failing Posting Command: no persistent side effect (Journal, Line, Evidence linkage, Audit Event, or Outbox event) is ever observable afterward, and the count of authoritative Posted Journals for that Tenant is unchanged from before the attempt (`POST-020`). |
| POST-T112 | For any generated Retried Posting Command, replayed any number of times, including concurrently, with the same Idempotency Key: the count of authoritative Posted Journals for that (Tenant, Idempotency Key) never exceeds one (`POST-003`). |
| POST-T113 | For any generated Posting Command whose Tenant does not match one or more of its referenced facts (Account, Actor, Evidence, existing Draft Journal): the command is always rejected, with zero persistent effect (`POST-001`). |

## 27. Golden scenarios

**These examples are illustrative only and are not normative business mappings.** Account identifiers are abstract placeholders (`ACCOUNT-CASH`, `ACCOUNT-INCOME`, and so on) — this document does not design final account codes, a Chart of Accounts, tax treatment, or any concrete business transaction mapping ([AETS-007 §2.2](../AETS-007-Posting-Command.md#22-out-of-scope)).

| ID | Scenario | Command | Expected outcome |
| --- | --- | --- | --- |
| POST-T114 | Simple cash sale | Idempotency Key `K1`, Tenant `TENANT-A`, Actor `ACTOR-1`, no Evidence required: Debit `ACCOUNT-CASH` RM100.00, Credit `ACCOUNT-INCOME` RM100.00. | Posts successfully; exactly one Posted Journal. |
| POST-T115 | Simple cash expense | Idempotency Key `K2`, evidence-backed by a referenced receipt: Debit `ACCOUNT-EXPENSE` RM45.50, Credit `ACCOUNT-CASH` RM45.50. | Posts successfully; Evidence linkage present, committed atomically. |
| POST-T116 | Owner capital contribution | Idempotency Key `K3`: Debit `ACCOUNT-CASH` RM5000.00, Credit `ACCOUNT-OWNER-CAPITAL` RM5000.00. | Posts successfully. |
| POST-T117 | Inactive Account rejection | POST-T114's command, but `ACCOUNT-CASH` is currently Inactive. | Rejected — distinct "Account Inactive" category (`POST-010`); no Journal posted. |
| POST-T118 | Non-posting Account rejection | POST-T114's command, but `ACCOUNT-INCOME` is a non-posting/group Account. | Rejected — distinct "Account non-posting-eligible" category (`POST-011`); no Journal posted. |
| POST-T119 | Wrong-Tenant Account rejection | POST-T114's command, but `ACCOUNT-CASH` belongs to `TENANT-B` while the command is scoped to `TENANT-A`. | Rejected — Tenant-ownership failure, before any persistent effect (`POST-001`, `POST-009`). |
| POST-T120 | Unbalanced command rejection | Debit `ACCOUNT-EXPENSE` RM45.50, Credit `ACCOUNT-CASH` RM45.00. | Rejected — balance-validation failure (`POST-016`); no Journal posted. |
| POST-T121 | Safe idempotent retry | POST-T114's command posts, creating Journal `J1`; the identical command, same Idempotency Key `K1`, is submitted again (client retry after a timeout). | Second submission returns `J1`'s result, marked as a replay; no second Journal created. |
| POST-T122 | Conflicting idempotency reuse | Idempotency Key `K1` (already used by POST-T114) resubmitted with a different amount, RM150.00 instead of RM100.00. | Rejected — distinct "conflicting idempotency reuse" category (`POST-004`); `J1` unchanged. |
| POST-T123 | External-source command with valid Source Fingerprint | A command derived from an imported bank statement row, carrying Source Fingerprint `FP1`: Debit `ACCOUNT-CASH` RM200.00, Credit `ACCOUNT-INCOME` RM200.00. | Posts successfully. |
| POST-T124 | External-source command missing required Source Fingerprint | The same imported-row-derived command as POST-T123, but with no Source Fingerprint. | Rejected — distinct "required Source Fingerprint missing" category (`POST-026`); no Journal posted. |
| POST-T125 | AI-originated valid command | An Accounting Proposal produced by AI, explicitly confirmed by Actor `ACTOR-1`, proposing a balanced, otherwise-valid Journal. | Posts successfully; Actor recorded is `ACTOR-1`, never the AI process. |
| POST-T126 | AI-originated invalid command | An Accounting Proposal produced by AI, confirmed by an Actor, but proposing an unbalanced Journal, regardless of the proposal's stated confidence score. | Rejected — balance-validation failure (`POST-016`, `POST-023`); no Journal posted, confidence score irrelevant to the outcome. |

## 28. Real PostgreSQL integration requirements

Per [AETS-007 §17](../AETS-007-Posting-Command.md#17-atomic-transaction-requirements) and this document's own Test Strategy (§4), the following claims MUST be proven against a real PostgreSQL instance — never SQLite, and never a purely in-process simulation, for any of them:

- **Atomicity and fault injection** (§18, POST-T070–POST-T076) — every fault-injection point requires a real transaction and a real, verifiable rollback, confirmed by querying the database directly afterward.
- **Concurrency** (§25, POST-T103–POST-T107) — every claim requires two genuinely independent PostgreSQL connections; an in-process double cannot prove a real row lock or a real unique-constraint race.
- **Idempotency and duplicate prevention's database-level authority** (§9 and §17, specifically POST-T067 and the concurrent half of POST-T103–POST-T105) — the (Tenant, Idempotency Key) uniqueness constraint's status as the *final* race-safe authority is, by definition, not provable without a real database enforcing it under real concurrent load.
- **Source Fingerprint uniqueness** (§10, POST-T031) — the same real-connections requirement applies to the Source Fingerprint uniqueness constraint as to the Idempotency Key one.

Dedicated, real-PostgreSQL integration test IDs, each an end-to-end aggregate proof of the claims above (mirroring [ATS-004 §21](ATS-004-Journal-Posting-Test-Specification.md#21-integration-tests)'s and [ATS-005](ATS-005-Chart-of-Accounts-Test-Specification.md)'s established `JRN-T082`-style pattern):

| ID | Test |
| --- | --- |
| POST-T127 | A full, well-formed Posting Command executes correctly end-to-end against a real PostgreSQL instance — validation, atomic persistence (Journal, Lines, Evidence linkage, Audit Event, Outbox event), and terminal result — not SQLite (`POST-019`, `POST-020`). |
| POST-T128 | A full idempotent-replay round trip (POST-T121) executes correctly against a real PostgreSQL instance, confirmed by querying the database directly for exactly one Journal row afterward (`POST-003`). |
| POST-T129 | A full conflicting-idempotency-reuse round trip (POST-T122) executes correctly against a real PostgreSQL instance, confirmed by querying the database directly for the original, unchanged Journal row afterward (`POST-004`). |
| POST-T130 | A full external-source-command round trip, both with a valid Source Fingerprint (POST-T123) and with a missing required one (POST-T124), executes correctly against a real PostgreSQL instance (`POST-026`). |
| POST-T131 | The database-level uniqueness constraint backing Idempotency Key duplicate prevention is confirmed to exist and to reject a raw, direct duplicate insert attempt at the schema level — not only through the application code path — the same defense-in-depth proof already established for `journals`/`journal_lines` in [ATS-004](ATS-004-Journal-Posting-Test-Specification.md#21-integration-tests). |

## 29. Deferred tests

- **Idempotency Key and Source Fingerprint derivation-mechanism-specific tests** — this document tests the conceptual contract (§9, §10); the concrete derivation and storage mechanism is deferred pending its own implementation decision ([AETS-007 §6](../AETS-007-Posting-Command.md#6-command-identity-and-idempotency), §26 of that document).
- **Concrete Actor and Source schema-specific tests** — this document tests presence, Tenant-match, and atomicity only (§11, §12, §22); detailed schema tests remain deferred pending a future Identity/Access specification, not yet created. **Concrete Audit Event schema and Evidence Reference/Linkage tests are no longer deferred**: [AETS-010](../AETS-010-Audit-Trail-Evidence-Linkage.md) (Audit Trail & Evidence Linkage) now exists and is `Active` (M6) — `POST-T093`–`POST-T095` are implemented against it, traced in full by [ATS-010](ATS-010-Audit-Trail-Test-Specification.md#5-traceability-matrix).
- **Outbox delivery/dispatcher tests** — this document tests only that the Outbox event is written atomically and never published from inside the transaction (§23); dispatcher, consumer, retry, and dead-letter tests are deferred to [ADR-0006](../../../adr/0006-transactional-outbox-pattern.md)'s own scope, unchanged here.
- **Business-specific Accounting Command tests** — invoicing, payment allocation, and other domain-specific commands remain deferred future subsections of AETS-007 itself ([AETS-007 §1](../AETS-007-Posting-Command.md#1-purpose), §26); this document tests the Posting Command only.
- **Detailed correction-workflow tests** — Reversal/Replacement frequency limits, approval requirements, and period-close interaction with posting — deferred pending AETS-006 (Posting Rules), exactly as [ATS-004 §23](ATS-004-Journal-Posting-Test-Specification.md#23-deferred-tests) already defers for the Journal side of the same boundary.
- **Property-based testing library selection** — deferred, not yet chosen for hore.my, exactly as already noted in [ATS-003 §23](ATS-003-Money-Test-Specification.md#23-deferred-tests) and [ATS-004 §23](ATS-004-Journal-Posting-Test-Specification.md#23-deferred-tests); §26 here specifies the required properties and generators, not the tooling.
- **Concrete terminal-result transport/API-shape tests** — [AETS-007 §19](../AETS-007-Posting-Command.md#19-success-semantics) states what the terminal result must convey, not its serialization; this document tests the conceptual content (§20), not a wire format.

## 30. Changelog

- **1.1.0 (2026-09-06):** M6 close: [AETS-010](../AETS-010-Audit-Trail-Evidence-Linkage.md) (Audit Trail & Evidence Linkage) now exists, resolving the forward-reference §29 previously carried. `POST-T093`–`POST-T095` (Audit Event) are implemented; `POST-T096`–`POST-T098` (Outbox) remain deferred, unchanged, since AETS-010 deliberately excludes the Outbox Event schema (AETS-010 §2.2). No test ID, invariant mapping, or other requirement changed — this is a deferred-item status update only, classified MINOR per [AETS-000 §9.1](../AETS-000.md#91-per-document-version) (a clarification, no previously specified behavior changed).
- **1.0.0 (2026-09-05):** Initial creation. Drafted per the task defining the normative test specification for AETS-007 (Accounting Commands & Posting Pipeline) before Posting Engine implementation begins. Introduces `POST-T001`–`POST-T131`, tracing every `POST-001`–`POST-027` invariant to at least one test ID (§6). No `JRN-T` ID reused, and no existing `POST-NNN` invariant renumbered. Mirrors the established structure and conventions of [ATS-004](ATS-004-Journal-Posting-Test-Specification.md) and [ATS-005](ATS-005-Chart-of-Accounts-Test-Specification.md) throughout.

  **Activation (2026-09-05, M4-T1 Close):** The Founder/CTO/Accounting Domain Reviewer review this document required, per [AETS-000 §8.3](../AETS-000.md#83-lifecycle), is complete. Status changes from `Draft` to `Active`; this document now governs Posting Engine test coverage. No test ID, invariant mapping, or other content changed as part of activation — `POST-T001`–`POST-T131` are preserved exactly as drafted.
