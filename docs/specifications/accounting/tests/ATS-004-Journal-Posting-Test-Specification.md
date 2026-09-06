# ATS-004: Journal & Posting Test Specification

- Status: Active
- Version: 1.6.0
- Effective date: 2026-09-06
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](../AETS-000.md), [AETS-001](../AETS-001-Accounting-Terminology.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-003](../AETS-003-Money-Specification.md), [AETS-004](../AETS-004-Journal-Posting-Model.md); [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0005](../../../adr/0005-ai-provider-abstraction.md), [ADR-0006](../../../adr/0006-transactional-outbox-pattern.md), [ADR-0007](../../../adr/0007-money-representation-strategy.md) (as amended)

## 1. Purpose

This document is the normative Accounting Test Specification (ATS) proving compliance with [AETS-004: Journal & Posting Model](../AETS-004-Journal-Posting-Model.md). It exists so that Journal/Posting implementation work has a precise, testable, traceable target before any code is written — exactly the coverage AETS-004 §25 said a future Journal & Posting ATS must provide.

Every test defined here is identified by a stable ID (`JRN-T001`–`JRN-T192`) and traced to the `JRN-NNN` invariant(s) it proves (§5). This document does not implement any test; it specifies what must be proven, at what level, and with what data, so that an implementer or an automated agent can build the actual test suite against it.

## 2. Scope

### 2.1 In scope

- Test-level classification (unit, aggregate, property-based, integration, architecture/static-analysis, security, golden) for every `JRN-NNN` invariant and every MUST-level requirement in AETS-004.
- A complete traceability matrix from `JRN-001`–`JRN-023` to test IDs.
- Concrete test cases — including golden MYR journal examples — for Journal/Journal Line construction, debit/credit semantics, posting validation, balance validation, atomicity, idempotency, concurrency, reversal, replacement, tenant isolation, audit traceability, and failure semantics.
- Generator definitions for property-based testing.
- Qualitative performance expectations (behavior only, no benchmark numbers, per this task's explicit instruction).

### 2.2 Out of scope

- Any implementation code, test framework configuration, or CI wiring — this document specifies tests, it does not write them.
- Anything AETS-004 itself defers or excludes (AETS-004 §2.2, §26): Chart of Accounts taxonomy, specific business transaction mappings, MyInvois, bank reconciliation, reporting algorithms, tax policy, AI classification logic, detailed correction-workflow mechanics, the concrete Posting Command schema/API contract, idempotency key derivation/storage schema, period-management interaction, and the Audit Event schema. Tests that would require any of these are listed as deferred (§23), not designed around a guess.
- Behavior AETS-004 does not define. Where this task's required test list touches something AETS-004 leaves ambiguous, this document flags it inline (see §7's note on `JRN-T002`–`JRN-T004`) rather than inventing an answer.
- Money's own construction, arithmetic, and persistence tests — already fully specified by [ATS-003](ATS-003-Money-Test-Specification.md). This document reuses Money as a dependency and does not re-test it.

## 3. Authority

This document is subordinate to [AETS-004](../AETS-004-Journal-Posting-Model.md), which is itself subordinate to [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../../adr/0006-transactional-outbox-pattern.md), and the authority hierarchy in [AETS-000 §3](../AETS-000.md#3-authority-hierarchy). Where a test specified here appears to require behavior AETS-004 does not define, that is a defect in this document to be corrected, not a license to invent AETS-004 content through a test (per [AETS-000 §8.4](../AETS-000.md#84-relationship-to-adrs-and-conflict-handling)). No contradiction between this document and AETS-004 was found while drafting it, so AETS-004 was not modified.

## 4. Test Strategy

- **Prove the invariant, not the implementation.** Every test traces to a `JRN-NNN` invariant or an explicit AETS-004 requirement — not to incidental implementation detail (mirroring [ATS-003 §4](ATS-003-Money-Test-Specification.md#4-test-philosophy)'s established approach for this series).
- **Exact-or-fail, tested both ways.** Per AETS-004 §21, every Posting Command must produce exactly a newly Posted Journal, an idempotent replay result, or a typed failure. This ATS tests every reachable outcome explicitly — a suite that only exercises the happy path does not prove AETS-004 compliance.
- **No silent behavior is acceptable evidence.** A test that merely checks "no exception was thrown" is insufficient; tests assert the specific expected Journal state or the specific expected failure category.
- **Failure-before-side-effect is provable, not assumed.** Any test claiming "rejected before any persistent effect" must verify the absence of a row/record afterward, not merely catch an exception (§10, §17).
- **Don't invent AETS-004 content.** Where AETS-004 leaves a case ambiguous or explicitly deferred (§26), this document says so rather than picking an answer a test would then silently enforce.
- **Real PostgreSQL for real guarantees.** Atomicity, concurrency, and durability claims are proven against a real PostgreSQL instance where a unit-level double cannot prove them (§21) — never silently substituted with SQLite, consistent with the precedent already established for Money's Persistence Adapter (`apps/api/tests/Feature/Infrastructure/Accounting/Money/MoneyPersistenceAdapterIntegrationTest.php`).

| Level | Purpose | Primary sections |
| --- | --- | --- |
| **Unit** | Isolated construction/validation behavior of a Journal or Journal Line, no persistence. | §7 |
| **Aggregate** | Journal-aggregate-level behavior: identity, state, immutability, correction-chain shape. | §8 |
| **Posting validation** | The ordered validation pipeline (AETS-004 §11) and balance validation. | §9 |
| **Atomicity / integration** | Transaction-boundary behavior; the parts requiring a real database are in §21. | §10, §21 |
| **Idempotency / concurrency** | Retry-safety and concurrent-request behavior. | §11, §12 |
| **Reversal / Replacement** | Correction-chain construction and neutrality. | §13, §14 |
| **Audit traceability** | Actor/Source/Evidence/Audit Event presence and atomicity. | §15 |
| **Tenant isolation** | Cross-tenant rejection. | §16 |
| **Failure semantics** | Outcome completeness and category distinguishability. | §17 |
| **Property-based** | Behavior proven across a generated range of inputs, not fixed examples. | §18 |
| **Golden cases** | Fixed, exact, hand-verified journal examples that must never silently change. | §19 |
| **Security** | Adversarial/boundary input and AI-authority-boundary tests. | §20 |

## 5. Traceability Matrix

Every `JRN-NNN` invariant from [AETS-004 §22](../AETS-004-Journal-Posting-Model.md#22-invariants) maps to at least one test ID below.

| Invariant | Summary | Test IDs |
| --- | --- | --- |
| JRN-001 | Tenant ownership | JRN-T016, JRN-T056, JRN-T057, JRN-T058, JRN-T076, JRN-T086, JRN-T101, JRN-T102, JRN-T120, JRN-T139, JRN-T149, JRN-T151, JRN-T152, JRN-T166, JRN-T167, JRN-T171 |
| JRN-002 | At least two lines | JRN-T002, JRN-T003, JRN-T004, JRN-T088, JRN-T093, JRN-T100, JRN-T106, JRN-T112, JRN-T113, JRN-T114, JRN-T118, JRN-T141, JRN-T142, JRN-T143, JRN-T144, JRN-T145, JRN-T156 |
| JRN-003 | Stable opaque identifier | JRN-T014, JRN-T087, JRN-T098, JRN-T103, JRN-T121, JRN-T134, JRN-T135 |
| JRN-004 | Recorded lifecycle state | JRN-T001, JRN-T015, JRN-T023, JRN-T024, JRN-T089, JRN-T090, JRN-T091, JRN-T092, JRN-T099, JRN-T104, JRN-T105, JRN-T115, JRN-T116, JRN-T117, JRN-T125, JRN-T136, JRN-T137, JRN-T138, JRN-T140, JRN-T180, JRN-T181, JRN-T182 |
| JRN-005 | Immutable posted Journal | JRN-T010, JRN-T017, JRN-T044, JRN-T048, JRN-T077, JRN-T172, JRN-T173, JRN-T174, JRN-T175, JRN-T176, JRN-T177, JRN-T178, JRN-T179, JRN-T181, JRN-T182, JRN-T183 |
| JRN-006 | No direct posted-Journal mutation | JRN-T018, JRN-T019, JRN-T020, JRN-T077, JRN-T164, JRN-T179, JRN-T183 |
| JRN-007 | Exact debit/credit balance | JRN-T025, JRN-T026, JRN-T045, JRN-T049, JRN-T062, JRN-T063, JRN-T067, JRN-T075, JRN-T095, JRN-T096, JRN-T097, JRN-T108, JRN-T119, JRN-T123, JRN-T128, JRN-T129, JRN-T132, JRN-T154, JRN-T155, JRN-T158, JRN-T159, JRN-T160 |
| JRN-008 | No dual-direction line | JRN-T005, JRN-T006, JRN-T007, JRN-T008, JRN-T110, JRN-T111, JRN-T126, JRN-T130, JRN-T133, JRN-T146, JRN-T161 |
| JRN-009 | No binary float | JRN-T012, JRN-T068, JRN-T127, JRN-T131, JRN-T147, JRN-T153, JRN-T157 |
| JRN-010 | Single Account reference | JRN-T009, JRN-T013, JRN-T107, JRN-T122, JRN-T150 |
| JRN-011 | Single Currency per Journal | JRN-T011, JRN-T094, JRN-T109, JRN-T124, JRN-T148 |
| JRN-012 | Atomic posting | JRN-T030, JRN-T031, JRN-T083, JRN-T085, JRN-T168, JRN-T169, JRN-T170 |
| JRN-013 | No network call inside the posting transaction | JRN-T032, JRN-T192 |
| JRN-014 | Idempotent posting | JRN-T033, JRN-T034, JRN-T035, JRN-T036, JRN-T065, JRN-T074, JRN-T084 |
| JRN-015 | Duplicate-effect prevention | JRN-T037, JRN-T038, JRN-T084, JRN-T185, JRN-T186 |
| JRN-016 | Full pre-persistence validation | JRN-T027, JRN-T028, JRN-T029, JRN-T031 |
| JRN-017 | Tenant ownership validated at posting | JRN-T027, JRN-T056, JRN-T057 |
| JRN-018 | Reversal neutrality | JRN-T042, JRN-T043, JRN-T064, JRN-T072 |
| JRN-019 | Reversal traceability | JRN-T021, JRN-T040, JRN-T041, JRN-T044 |
| JRN-020 | Replacement traceability | JRN-T022, JRN-T046, JRN-T047, JRN-T048, JRN-T073 |
| JRN-021 | Actor traceability | JRN-T050, JRN-T051, JRN-T055 |
| JRN-022 | Source/evidence traceability | JRN-T052, JRN-T053, JRN-T054, JRN-T055 |
| JRN-023 | No AI posting authority | JRN-T051, JRN-T080, JRN-T081 |
| JRN-024 | Financial Date always present, never self-generated | JRN-T193, JRN-T196, JRN-T199, JRN-T202, JRN-T205, JRN-T206, JRN-T207 |
| JRN-025 | Posted-at set exactly once, at the durable posting moment | JRN-T194, JRN-T195, JRN-T197, JRN-T198, JRN-T203, JRN-T204, JRN-T205, JRN-T207, JRN-T208 |

## 6. Test Data Strategy

- **Abstract identifiers only.** Account, Tenant, Actor, and Idempotency Key values used throughout this document are synthetic placeholders (`ACCOUNT-CASH`, `TENANT-A`, `ACTOR-1`, `K1`, and so on) — never a real Chart of Accounts code (AETS-004 §2.2) and never real business data.
- **MYR only.** All test data uses MYR at scale 2, consistent with MVP scope ([`HORE_MY_MASTER_CONTEXT.md`](../../../product/reference/HORE_MY_MASTER_CONTEXT.md) §9) and reuses [ATS-003](ATS-003-Money-Test-Specification.md#19-golden-test-cases)'s existing golden MYR values where convenient, rather than inventing new ones.
- **Two-Tenant minimum for isolation tests.** Every tenant-isolation test (§16) requires at least two distinct, otherwise-valid Tenants so a cross-tenant reference is genuinely cross-tenant, not merely a missing value.
- **Real PostgreSQL for integration/concurrency evidence.** Tests in §10 (atomic rollback specifically), §12 (concurrency), and §21 (integration) require the project's own Docker Postgres service ([`docker-compose.yml`](../../../../docker-compose.yml)), not SQLite — mirroring the constraint already established for Money's Persistence Adapter integration tests. A unit-level double may simulate the same scenario for fast feedback, but does not substitute for the real-database proof.
- **Fault-injection points.** Atomic-rollback tests (§10, §21) require the ability to force a failure after some, but not all, of a Posting Command's required writes (Journal, Line, evidence linkage, audit event, outbox event) — the specific injection mechanism is an implementation detail this document does not design.
- **Property-based generators** are defined in §18.1.

## 7. Unit Tests

| ID | Test |
| --- | --- |
| JRN-T001 | A Draft Journal can be assembled with no ledger effect. |
| JRN-T002 | A Journal with zero Journal Lines is rejected. |
| JRN-T003 | A Journal with exactly one Journal Line is rejected. |
| JRN-T004 | A Journal with exactly two Journal Lines is accepted — the minimum is satisfied. |
| JRN-T005 | A Journal Line with Debit direction is valid. |
| JRN-T006 | A Journal Line with Credit direction is valid. |
| JRN-T007 | A Journal Line cannot represent both Debit and Credit simultaneously. |
| JRN-T008 | A Journal Line cannot represent neither direction. |
| JRN-T009 | Two Journal Lines referencing the same Account, with valid distinct directions, are both accepted within one Journal — a duplicate Account reference is allowed when the resulting Journal is otherwise valid. |
| JRN-T010 | A Journal Line's Money is immutable: no Journal operation mutates a recorded Line's amount in place. |
| JRN-T011 | All Journal Lines within one Journal must share the same Currency; a mixed-Currency Journal is rejected. |
| JRN-T012 | A Journal Line rejects a native float amount at the type level, before any grammar or value validation runs. |
| JRN-T013 | A Journal Line referencing zero or multiple Accounts is not constructible. |
| JRN-T014 | Every Journal is assigned a stable, opaque identifier at creation, immutable for its lifetime. |
| JRN-T015 | A Journal records its current lifecycle state, and only Draft and Posted are valid values. |

> **Note on `JRN-T002`–`JRN-T004`.** These three tests together prove the minimum-line-count requirement (`JRN-002`): a Journal MUST contain at least two Journal Lines. This is final for the current specification baseline, per [AETS-004 §7](../AETS-004-Journal-Posting-Model.md#7-journal-line) and [AETS-002 §4](../AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 1.

**Reconstitution** — `Journal::reconstitute(...)` is the domain-owned counterpart to `create()` used to load a previously-persisted Journal — Draft or Posted — rather than forcing persistence code through `create(...)->post()`, which would misrepresent restoration of already-decided state as a new posting decision. Unlike a typical reconstitution counterpart that skips re-validating what construction already checked, `reconstitute()` validates the exact same structural financial invariants `create()` does every time — persisted data is not trusted merely because it came from storage:

| ID | Test |
| --- | --- |
| JRN-T086 | `reconstitute()` restores TenantId exactly as supplied. |
| JRN-T087 | `reconstitute()` restores JournalId exactly as supplied. |
| JRN-T088 | `reconstitute()` restores the exact Journal Line list — same count, same instances, never cloned or replaced. |
| JRN-T089 | `reconstitute()` can restore a Draft Journal exactly. |
| JRN-T090 | `reconstitute()` can restore a Posted Journal exactly. |
| JRN-T091 | `reconstitute()` never calls `post()` — reconstituting a Posted Journal does not invoke the Draft -> Posted transition. |
| JRN-T092 | Reconstituting a Posted Journal does not throw the "already posted" failure `post()` itself raises (`JRN-T024`) — that failure is specific to a repeated *transition* attempt, not to state restoration. |
| JRN-T093 | `reconstitute()` still enforces the minimum two Journal Lines requirement (`JRN-002`) — a persisted line set with fewer than two lines is rejected, not trusted. |
| JRN-T094 | `reconstitute()` still rejects a mixed-Currency Journal Line set (`JRN-011`). |
| JRN-T095 | `reconstitute()` still rejects an unbalanced Journal Line set (`JRN-007`). |
| JRN-T096 | A Journal Line set claimed to already be Posted, but financially invalid (unbalanced, mixed-Currency, or insufficient lines), is still rejected — the supplied lifecycle state does not exempt the data from validation. |
| JRN-T097 | Exact Money balance remains mandatory through `reconstitute()`, computed via the same Money arithmetic `create()` uses — never native float, never a tolerance window. |
| JRN-T098 | Identity equality remains based on JournalId regardless of which factory produced a Journal — a `create()`d Journal and a `reconstitute()`d Journal sharing the same identifier are equal. |
| JRN-T099 | `reconstitute()` introduces no posting, Audit Event, Outbox, or idempotency-key side effect of any kind — it performs no I/O. |
| JRN-T100 | Journal Line order is preserved exactly through `reconstitute()` — order is part of what "exact" restoration means, not merely count and content. |

**Persistence Adapter** — `JournalPersistenceAdapter` (M3-T8) maps a Journal (header + Lines) to and from a persistence-safe representation. Since `JournalLine` carries no identifier of its own, each persisted line row carries an explicit `line_position` standing in for order at the adapter level only — the production schema's own line-identity/order decision remains deferred. The read path never trusts persisted data: it reconstructs every value through its own Value Object's validated factory and completes reconstruction through `Journal::reconstitute()`, never `create(...)->post()`:

| ID | Test |
| --- | --- |
| JRN-T101 | A Journal maps to its persisted header representation (`tenant_id`, `journal_id`, `state`). |
| JRN-T102 | TenantId round-trips exactly through the persisted header. |
| JRN-T103 | JournalId round-trips exactly through the persisted header. |
| JRN-T104 | Draft state round-trips exactly through the persisted header. |
| JRN-T105 | Posted state round-trips exactly through the persisted header. |
| JRN-T106 | A Journal's Lines map to persisted line rows, one row per Line. |
| JRN-T107 | AccountId round-trips exactly through a persisted line. |
| JRN-T108 | Money's exact minor-units amount round-trips through a persisted line. |
| JRN-T109 | Currency round-trips exactly through a persisted line. |
| JRN-T110 | Debit direction round-trips exactly through a persisted line. |
| JRN-T111 | Credit direction round-trips exactly through a persisted line. |
| JRN-T112 | Each persisted line row carries an explicit `line_position` reflecting the Line's original position. |
| JRN-T113 | Line order is restored from `line_position` exactly, even when persisted rows are supplied out of order. |
| JRN-T114 | A multi-line Journal round-trips exactly, preserving every Line. |
| JRN-T115 | A persisted Draft Journal reconstructs as Draft. |
| JRN-T116 | A persisted Posted Journal reconstructs as Posted. |
| JRN-T117 | Reconstructing a Posted Journal uses `Journal::reconstitute()`, never `Journal::post()` — no re-posting side effect, and no "already posted" failure. |
| JRN-T118 | A persisted Journal with fewer than two Lines is rejected on reconstruction. |
| JRN-T119 | A persisted Journal whose Lines do not balance exactly is rejected on reconstruction, even when the row claims to already be Posted. |
| JRN-T120 | A malformed persisted TenantId is rejected, via TenantId's own existing validation. |
| JRN-T121 | A malformed persisted JournalId is rejected, via JournalId's own existing validation. |
| JRN-T122 | A malformed persisted AccountId is rejected, via AccountId's own existing validation. |
| JRN-T123 | A malformed persisted Money amount is rejected, via MinorUnits' own existing validation. |
| JRN-T124 | A malformed persisted Currency is rejected, via Currency's own existing validation. |
| JRN-T125 | An unsupported persisted Journal state is rejected. |
| JRN-T126 | An unsupported persisted Journal Line direction is rejected. |
| JRN-T127 | The persisted representation, header and lines alike, carries no native binary float anywhere. |
| JRN-T128 | No persisted line carries a signed-amount field — Direction alone carries the accounting sign; Money remains a non-negative magnitude. |
| JRN-T129 | No persisted representation, header or lines, carries a debit-total, credit-total, or other authoritative balance-total field. |
| JRN-T130 | Direction is persisted as a field independent from Money's amount and Currency — never inferred from, or embedded in, the Money value. |
| JRN-T202 | (M8) `financial_date` is persisted as a plain `Y-m-d` string and round-trips back to the exact calendar date. |
| JRN-T203 | (M8) `posted_at` is persisted as `null` for a Draft header and round-trips exactly for a Posted one. |
| JRN-T204 | (M8) A persisted header whose `state`/`posted_at` disagree (a Draft claiming `posted_at`, or Posted with none) is rejected on reconstruction. |

> **Note on mixed-Currency adapter coverage.** `Currency`'s registry currently supports only `MYR` (M1-T2), so a genuinely mixed-Currency persisted line pair — two lines each carrying a *different*, both-otherwise-valid Currency — cannot be expressed through this adapter's string-based read path today; any second identifier is rejected as malformed (`JRN-T124`) before a mismatch could even be compared. The single-Currency invariant itself (`JRN-011`) remains enforced by `Journal::reconstitute()` regardless. A genuine cross-currency adapter integration test is deferred until a second Currency becomes an approved supported domain currency — this is not invented here merely to exercise the adapter.

## 8. Aggregate Tests

| ID | Test |
| --- | --- |
| JRN-T016 | A Journal belongs to exactly one Tenant; a Journal with no Tenant is not constructible. |
| JRN-T017 | Once a Journal is Posted, no field of the Journal or any of its Lines changes. |
| JRN-T018 | An application-level update operation against a Posted Journal or Line is rejected. |
| JRN-T019 | An application-level delete operation against a Posted Journal or Line is rejected. |
| JRN-T020 | *(Architecture)* No exposed application operation is capable of directly updating or deleting a Posted Journal or its Lines, other than via Reversal or Replacement — verified by an inventory of the public posting-adjacent surface, not by exercising every possible call. |
| JRN-T021 | A Reversal Journal carries a reference to its original Journal; an ordinary (non-correction) Journal carries no correction-chain reference. |
| JRN-T022 | A Replacement Journal carries a reference to the correction chain; an ordinary Journal carries none. |

### 8.1 Financial Date and Posted-At (M8)

| ID | Test |
| --- | --- |
| JRN-T193 | `create()` persists the exact caller-supplied `financialDate`. |
| JRN-T194 | A Draft Journal's `postedAt` is always `null`. |
| JRN-T195 | `post($postedAt)` sets `postedAt` to exactly the caller-supplied moment. |
| JRN-T196 | `post()` preserves `financialDate` exactly, unchanged by the Draft -> Posted transition. |
| JRN-T197 | Reconstituting a Draft with a non-null `postedAt` is rejected (`InconsistentPostedAtException`). |
| JRN-T198 | Reconstituting a Posted Journal with no `postedAt` is rejected (`InconsistentPostedAtException`). |
| JRN-T199 | *(Architecture)* Neither `financialDate` nor `postedAt` is ever self-generated by `Journal` — no `new \DateTimeImmutable('now')` appears anywhere in its executable code. |
| JRN-T200 | A Reversal carries its own explicitly supplied `financialDate`, never the original Journal's. |
| JRN-T201 | A Replacement carries its own explicitly supplied `financialDate`, never the Reversal's. |

## 9. Posting Validation Tests

| ID | Test |
| --- | --- |
| JRN-T023 | A Posting Command may transition only a Draft Journal to Posted. |
| JRN-T024 | Attempting to post an already-Posted Journal is rejected with a typed "already posted" failure, not silently re-posted and not silently accepted as a no-op. |
| JRN-T025 | A Journal whose total Debit equals total Credit exactly posts successfully. |
| JRN-T026 | A Journal whose total Debit does not equal total Credit is rejected and never posts. |
| JRN-T027 | Tenant ownership is validated before line-level or balance validation runs (pipeline ordering, AETS-004 §11 step 1). |
| JRN-T028 | Every Journal Line is validated in full (Account, Direction, Money) before balance validation runs (§11 step 4 before step 5). |
| JRN-T029 | Balance validation is the last check before persistence; no persistent effect precedes it. |

## 10. Atomicity Tests

| ID | Test |
| --- | --- |
| JRN-T030 | A fault injected after some, but not all, of a Posting Command's required writes rolls back the entire posting transaction — no partial Journal, Line, evidence linkage, audit event, or outbox record remains. |
| JRN-T031 | A Posting Command that fails validation, at any pipeline stage (§9), produces zero persistent effect — no Journal row and no Line row exist afterward, at any state. |
| JRN-T032 | *(Architecture)* No network call or queue publication executes inside the posting database transaction — verified by scanning the posting code path for an external call within the transactional boundary. |

## 11. Idempotency Tests

| ID | Test |
| --- | --- |
| JRN-T033 | Resubmitting an already-processed Posting Command (same Tenant, same Idempotency Key) does not create a second Journal. |
| JRN-T034 | A resubmitted Posting Command returns or identifies the original Journal's result deterministically. |
| JRN-T035 | A Posting Command without an Idempotency Key is not a valid, callable invocation. |
| JRN-T036 | The (Tenant, Idempotency Key) → Journal association is recorded within the same atomic transaction that posts the Journal — not as a separate, later write. |

## 12. Concurrency Tests

| ID | Test |
| --- | --- |
| JRN-T037 | Two simultaneous Posting Commands with the same Idempotency Key and Tenant produce exactly one Journal; the command that loses the race returns the winner's result rather than erroring destructively or creating a second Journal. |
| JRN-T038 | A Draft Journal posted concurrently by two different Posting Commands does not produce two Posted Journals from the same Draft. |
| JRN-T039 | Two concurrent, unrelated Posting Commands (different Idempotency Keys, potentially touching the same Account) both succeed independently and correctly, with no lost update. |

## 13. Reversal Tests

| ID | Test |
| --- | --- |
| JRN-T040 | A Reversal is created as a new Journal, with its own stable identifier. |
| JRN-T041 | A Reversal references the original Journal it reverses. |
| JRN-T042 | For every line of an original Journal, its Reversal contains a corresponding line with the same Account and the same exact Money magnitude, but the opposite Direction. |
| JRN-T043 | The combined net effect of an original Journal and its Reversal is exactly zero, per Account. |
| JRN-T044 | The original Journal remains unchanged after its Reversal is posted. |
| JRN-T045 | A Reversal must itself balance; an incorrectly constructed Reversal is rejected like any other unbalanced Journal. |

## 14. Replacement Tests

| ID | Test |
| --- | --- |
| JRN-T046 | A Replacement is created as a new Journal, distinct from its Reversal. |
| JRN-T047 | A Replacement references both its Reversal and, transitively, the original Journal being corrected. |
| JRN-T048 | A Replacement does not overwrite the original Journal's stored data. |
| JRN-T049 | A Replacement must itself balance; an incorrectly constructed Replacement is rejected like any other unbalanced Journal. |

## 15. Audit Traceability Tests

| ID | Test |
| --- | --- |
| JRN-T050 | Every Posted Journal records the identified Actor of the Posting Command that posted it. |
| JRN-T051 | AI, or any other proposal-producing process, is never recorded as the Actor accepting a Posting Command. |
| JRN-T052 | Every Journal retains a traceable Source reference to the Accounting Command, accepted Accounting Proposal, or correction reference that gave rise to it. |
| JRN-T053 | A Journal backed by Evidence retains its Evidence linkage, committed atomically with the Journal. |
| JRN-T054 | A Journal with no independent evidence of its own (for example, a pure Reversal) is not required to fabricate one. |
| JRN-T055 | Every successful posting produces an Audit Event capturing Actor, Tenant, source, and time, committed atomically with the Journal. |

## 16. Tenant Isolation Tests

| ID | Test |
| --- | --- |
| JRN-T056 | A Posting Command referencing an Account belonging to a different Tenant is rejected before any persistent effect. |
| JRN-T057 | A Posting Command referencing Evidence, an Actor, or a Draft Journal belonging to a different Tenant is rejected before any persistent effect. |
| JRN-T058 | A Reversal or Replacement referencing a Journal belonging to a different Tenant than the command itself is rejected. |

## 17. Failure Semantics Tests

| ID | Test |
| --- | --- |
| JRN-T059 | Every Posting Command produces exactly one of: a newly Posted Journal, an idempotent replay result, or a typed failure — never a third or ambiguous outcome. |
| JRN-T060 | Each failure category named in AETS-004 §21 is distinguishable by callers (e.g. by type), never merged into one generic error. |
| JRN-T061 | A vendor-library exception encountered anywhere in the posting path (for example, from Money's own arithmetic or persistence mapping) does not escape as a vendor type here either. |

## 18. Property Tests

### 18.1 Generators

| Generator | Produces |
| --- | --- |
| **Balanced Journal Line sets** | Sets of two or more lines, generated Account/Direction/Money combinations, whose total Debit exactly equals total Credit at MYR scale 2. |
| **Unbalanced Journal Line sets** | The same shape, deliberately perturbed by one minor unit or more so total Debit never equals total Credit. |
| **Valid Posting Commands** | Balanced Journal Line sets paired with a valid Tenant, Actor, and freshly generated Idempotency Key. |
| **Retried Posting Commands** | A single generated Posting Command, replayed a randomized number of times (including concurrently), with the same Idempotency Key. |
| **Posted Journals for Reversal** | Journals produced by successfully posting a generated valid Posting Command, used as the input to a generated Reversal. |
| **Failing Posting Commands** | Generated commands deliberately violating exactly one pipeline stage (§9) at a time: wrong Tenant, malformed line, unbalanced total, or already-Posted target. |

### 18.2 Properties

| ID | Property |
| --- | --- |
| JRN-T062 | For any generated balanced Journal Line set: the Posting Command always succeeds. *(Debit == Credit always posts.)* |
| JRN-T063 | For any generated unbalanced Journal Line set: the Posting Command always fails, and never posts. *(Debit != Credit never posts.)* |
| JRN-T064 | For any generated Posted Journal: `Reversal(original)` combined with `original` always produces exactly zero net financial effect, per Account. |
| JRN-T065 | For any generated Posting Command retried any number of times, including concurrently, with the same Idempotency Key: at most one Journal is ever created. |
| JRN-T066 | For any generated Posting Command that fails at any single validation stage: no persistent side effect (Journal, Line, audit event, or outbox event) is ever observable afterward. |
| JRN-T067 | For any generated Posted Journal, at any point after posting: it is always found balanced (total Debit == total Credit) — no Posted Journal is ever observed unbalanced. |
| JRN-T068 | Across every generator category in §18.1, no generated Journal or Journal Line construction or posting attempt ever causes a native binary float to appear in Journal state or output. |

## 19. Golden Test Cases

**These examples mirror [AETS-004 §24](../AETS-004-Journal-Posting-Model.md#24-examples-informative)'s informative examples exactly, made concrete as data-driven test cases.** Account identifiers remain abstract placeholders — this document does not design final account codes (AETS-004 §2.2).

| ID | Case | Journal Lines | Expected outcome |
| --- | --- | --- | --- |
| JRN-T069 | Cash sale | Debit `ACCOUNT-CASH` RM100.00; Credit `ACCOUNT-INCOME` RM100.00 | Posts successfully; balanced. |
| JRN-T070 | Cash expense | Debit `ACCOUNT-EXPENSE` RM45.50; Credit `ACCOUNT-CASH` RM45.50 | Posts successfully; balanced. |
| JRN-T071 | Owner capital contribution | Debit `ACCOUNT-CASH` RM5000.00; Credit `ACCOUNT-OWNER-CAPITAL` RM5000.00 | Posts successfully; balanced. |
| JRN-T072 | Reversal | Given Journal `J1` (`JRN-T069`); Reversal `J2`: Debit `ACCOUNT-INCOME` RM100.00, Credit `ACCOUNT-CASH` RM100.00, referencing `J1` | `J2` posts successfully; `J1` + `J2` net exactly RM0.00 per Account; `J1` unchanged. |
| JRN-T073 | Reversal + Replacement | After `J2` (`JRN-T072`) reverses `J1`; Replacement `J3`: Debit `ACCOUNT-CASH` RM120.00, Credit `ACCOUNT-INCOME` RM120.00, referencing the correction chain (`J2`, transitively `J1`) | `J3` posts successfully; `J1` and `J2` unchanged; `J3` is the corrected, currently-effective entry. |
| JRN-T074 | Duplicate retry | `JRN-T069`'s Posting Command, Idempotency Key `K1`, submitted twice | First submission posts `J1`; second submission (same `K1`) returns `J1`'s result; no second Journal exists. |
| JRN-T075 | Unbalanced journal | Debit `ACCOUNT-EXPENSE` RM45.50; Credit `ACCOUNT-CASH` RM45.00 | Rejected — balance validation failure; no Journal posted. |
| JRN-T076 | Wrong tenant | `JRN-T069`'s lines, but `ACCOUNT-CASH` belongs to `TENANT-B` while the Posting Command is scoped to `TENANT-A` | Rejected — tenant-ownership failure, before any persistent effect. |
| JRN-T077 | Posted mutation attempt | An attempt to directly update `J1`'s (`JRN-T069`) Credit line amount, and a separate attempt to delete `J1` entirely | Both rejected — no application-level operation performs either; `J1` is unchanged. |

## 20. Security Tests

| ID | Test |
| --- | --- |
| JRN-T078 | An adversarially long or malformed Idempotency Key is rejected via a bounded, deterministic check before it is used for a lookup, mirroring the input-hygiene pattern already established for Money ([AETS-003 §18](../AETS-003-Money-Specification.md#18-security-and-validation)). |
| JRN-T079 | An adversarially malformed or oversized Account identifier supplied to a Journal Line is rejected rather than silently accepted as unvalidated opaque input. |
| JRN-T080 | *(Architecture / integration)* An attempted direct Posting Command execution, confirmation, or validation-pipeline bypass attributed to an AI or other proposal-producing actor is rejected — the command path requires an authorized human Actor or a deterministic Accounting Core process. |
| JRN-T081 | No code path allows treating an Accounting Proposal as pre-confirmed — an AI-originated proposal must pass through explicit confirmation before it can become a Posting Command, regardless of its confidence score. |

## 21. Integration Tests

*(These tests exercise Journal/Posting behavior against a real PostgreSQL instance, per [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../../adr/0006-transactional-outbox-pattern.md), and the precedent already established for Money's Persistence Adapter — see §4, §6.)*

| ID | Test |
| --- | --- |
| JRN-T082 | A full Posting Command round trip (validation, atomic persistence, audit event, outbox event) executes correctly against a real PostgreSQL instance, not SQLite. |
| JRN-T083 | A fault injected mid-transaction against a real PostgreSQL instance causes a real, verifiable rollback — confirmed by querying the database directly afterward for zero resulting rows. |
| JRN-T084 | Two concurrent Posting Commands with the same Idempotency Key, executed against a real PostgreSQL instance under genuine concurrent connections, produce exactly one committed Journal. |
| JRN-T085 | The outbox event associated with a committed Journal is durably present in the same real-database transaction as the Journal itself, verified by querying the outbox table immediately after commit, before any dispatcher runs. |
| JRN-T131 | The Journal persistence adapter's shape (header and lines) round-trips through a real PostgreSQL instance, not SQLite. |
| JRN-T132 | A large, exact `BIGINT` Money amount round-trips through real PostgreSQL with no precision loss. |
| JRN-T133 | Journal state and Journal Line direction each survive a real PostgreSQL round-trip exactly. |

**Production schema (M3-T9)** — `database/migrations/..._create_journals_and_journal_lines_tables.php` now exists: real `journals` and `journal_lines` tables mapped exactly to `JournalPersistenceAdapter`'s row shapes, with defense-in-depth `CHECK`/primary-key/composite-foreign-key constraints mirroring the already-settled domain rules above. Every ID below is proven against a real PostgreSQL instance, through the real Laravel migrator, never a hand-copied re-implementation of the schema and never SQLite:

| ID | Test |
| --- | --- |
| JRN-T134 | The `journals` table creates successfully, with exactly `tenant_id`, `journal_id`, `state`. |
| JRN-T135 | `journal_id` uniqueness is enforced by the table's own primary-key constraint. |
| JRN-T136 | Draft state is accepted by the production `journals` schema. |
| JRN-T137 | Posted state is accepted by the production `journals` schema. |
| JRN-T138 | An unsupported `state` value is rejected by a `CHECK` constraint naming exactly the two canonical Journal states. |
| JRN-T139 | `tenant_id` is `NOT NULL` on `journals`. |
| JRN-T140 | `state` is `NOT NULL` on `journals`. |
| JRN-T141 | The `journal_lines` table creates successfully, with exactly `tenant_id`, `journal_id`, `line_position`, `account_id`, `amount`, `currency`, `direction`. |
| JRN-T142 | A valid Journal Line row is accepted by the production schema. |
| JRN-T143 | A duplicate `line_position` within the same Journal is rejected by the table's own primary-key constraint `(journal_id, line_position)`. |
| JRN-T144 | The same `line_position` is permitted across two different Journals. |
| JRN-T145 | A negative `line_position` is rejected by a `CHECK` constraint. |
| JRN-T146 | An unsupported `direction` value is rejected by a `CHECK` constraint naming exactly the two canonical Directions. |
| JRN-T147 | `amount` is `NOT NULL` on `journal_lines`. |
| JRN-T148 | `currency` is `NOT NULL` on `journal_lines`. |
| JRN-T149 | A Journal Line referencing a nonexistent Journal is rejected by the composite `(tenant_id, journal_id)` foreign key. |
| JRN-T150 | A Journal Line referencing a nonexistent Account is rejected by the composite `(tenant_id, account_id)` foreign key. |
| JRN-T151 | A Journal Line referencing an Account belonging to a different Tenant than its own Journal is rejected — the same composite-foreign-key technique already established for Account's same-Tenant parent integrity (M2-T8.1), applied here across `journals`/`journal_lines`/`accounts`. |
| JRN-T152 | A Journal Line referencing an Account in the same Tenant as its Journal is accepted. |
| JRN-T153 | A large, exact `BIGINT` `amount` round-trips through the production schema with no precision loss. |
| JRN-T154 | The production schema carries no signed-amount column — Direction alone carries the accounting sign; `amount` remains a non-negative magnitude. |
| JRN-T155 | Neither `journals` nor `journal_lines` carries a `debit_total`, `credit_total`, or `balance` column. |
| JRN-T156 | Multiple Journal Lines for one Journal are accepted. |
| JRN-T157 | The production migration is reversible through Laravel's own migrator: `migrate:rollback` drops both tables cleanly, and the migration re-applies cleanly afterward. |
| JRN-T158 | The production schema accepts a zero-amount Journal Line — AETS-004 does not prohibit one, and no minimum monetary value is invented. |
| JRN-T159 | The production schema accepts a positive `BIGINT` Money magnitude. |
| JRN-T160 | The production schema rejects a negative Journal Line `amount` via a `CHECK` constraint — Money remains a non-negative magnitude at the database level too. |
| JRN-T161 | Direction remains the sole Debit/Credit polarity representation the database permits — no second, competing representation where sign is encoded inside `amount` exists or is accepted. |

> **Note on minimum-two-lines/single-Currency/exact-balance at the schema level.** These remain aggregate/Posting-Engine invariants, not row-level `CHECK` constraints (a single row cannot express a fact about the whole Journal) — see the migration's own docblock. No test ID above claims schema-level enforcement of them; `Journal::create()`/`Journal::reconstitute()` remain the sole enforcement point, exactly as already established (`JRN-002`, `JRN-007`, `JRN-011`).

**Journal Repository (M3-T10)** — `app/Infrastructure/Accounting/Journal/JournalRepository.php` now exists: the controlled write/read boundary onto the production `journals`/`journal_lines` schema above, using `JournalPersistenceAdapter` as its sole domain <-> persistence mapping. `JournalRepository` is a persistence primitive, not posting authority — it may persist a Journal whose domain state already arrives Posted (a future Posting Engine must be able to persist a fully-validated Posted Journal atomically with its own Audit/Outbox effects), but `save()` itself is not a Posting Command, grants no posting authorization, and enforces no idempotency (`JRN-014` remains entirely deferred to the future Posting Engine). Every ID below is proven against a real PostgreSQL instance, never SQLite, mirroring the precedent already established for `AccountRepository`/`AccountRepositoryIntegrationTest`.

| ID | Test |
| --- | --- |
| JRN-T162 | `JournalRepository::save(Journal $journal): void` exists, exactly as declared — no additional or missing parameter. |
| JRN-T163 | `JournalRepository::findById(TenantId $tenantId, JournalId $journalId): ?Journal` exists, exactly as declared. |
| JRN-T164 | No `delete`/`remove`/`destroy`/`purge` method exists on `JournalRepository` — no hard-delete path for Journal history. |
| JRN-T165 | *(Architecture)* Neither `save()` nor `findById()` ever returns a raw database row; `findById()` returns only a `Journal` or `null`. |
| JRN-T166 | `findById()`'s header lookup is scoped by both `TenantId` and `JournalId` together — no public Journal lookup relies on `JournalId` alone. |
| JRN-T167 | Against real PostgreSQL: `findById(Tenant A, JournalId belonging to Tenant B)` returns `null`; `findById(Tenant B, that same JournalId)` returns the Journal. |
| JRN-T168 | Against real PostgreSQL: a new Journal's header insert and every Journal Line insert commit together inside one transaction. |
| JRN-T169 | Against real PostgreSQL: a fault injected during the Journal Line insert (a Line referencing a nonexistent Account — a domain-valid but real, unavoidable failure, since `AccountId` is opaque to the Domain layer) rolls back the already-issued header insert too. |
| JRN-T170 | Against real PostgreSQL: after the rolled-back `save()` in `JRN-T169`, zero `journals` rows and zero `journal_lines` rows exist for that `JournalId` — no partial Journal persistence remains. |
| JRN-T171 | `TenantId` cannot change for an existing `JournalId`; `save()` rejects the attempt and the existing row is left unchanged. |
| JRN-T172 | An existing Journal Line's `AccountId` cannot change via `save()`. |
| JRN-T173 | An existing Journal Line's Money amount cannot change via `save()`. |
| JRN-T174 | An existing Journal Line's Currency cannot change via `save()`. |
| JRN-T175 | An existing Journal Line's Direction cannot change via `save()`. |
| JRN-T176 | An existing Journal's Line order cannot change via `save()`. |
| JRN-T177 | A Line cannot be silently added to an existing Journal via `save()`. |
| JRN-T178 | A Line cannot be silently removed from an existing Journal via `save()`. |
| JRN-T179 | Any rejected immutable-state `save()` attempt (a Posted -> Draft reversal, or a Draft content change) leaves the existing persisted header and every existing Journal Line row byte-for-byte unchanged, checked directly against real PostgreSQL after each rejection. |
| JRN-T180 | An existing Draft Journal's valid Draft -> Posted transition persists successfully via `save()`. |
| JRN-T181 | The exact Journal Line set (content and order) remains unchanged across a persisted Draft -> Posted transition. |
| JRN-T182 | Persisting a Posted -> Draft transition is rejected by `save()`. |
| JRN-T183 | An existing Posted Journal cannot be rewritten by any subsequent `save()` call — Posted is terminal at the repository, regardless of what the incoming data claims. |
| JRN-T184 | The existing Journal's header row is locked (`SELECT ... FOR UPDATE`) inside `save()`'s transaction before its persisted state is compared against or updated. |
| JRN-T185 | Against real PostgreSQL, using two genuinely independent connections: a concurrent conflicting `save()` blocks on the row lock held by an in-flight `save()`/comparison for the same Journal, then fails — never silently overwriting authoritative history. |
| JRN-T186 | After the blocked/failed concurrent `save()` attempt in `JRN-T185`, the final persisted Journal (header and lines) remains exactly what it was before the conflict — coherent, never partially applied, and no duplicate Line row was created. |
| JRN-T187 | Against real PostgreSQL: `findById()` restores the exact Journal Line order. |
| JRN-T188 | Against real PostgreSQL: `findById()` restores a large, exact `BIGINT` Money amount with no precision loss. |
| JRN-T189 | A row satisfying every database constraint but carrying a value one of the Value Objects' own validation rejects (e.g. an unsupported Currency identifier) still fails on read, propagated unmodified from `JournalPersistenceAdapter`/the Domain — never swallowed by the repository. |
| JRN-T190 | *(Architecture)* `JournalPersistenceAdapter` remains the sole domain <-> persistence mapping boundary — `JournalRepository` never assembles a `Journal` or a persisted row shape independently of it. |
| JRN-T191 | *(Architecture)* `JournalRepository` contains no Posting Engine, idempotency, Audit Event, Actor/Source/Evidence, or Outbox behavior — a pure persistence boundary, proven by the absence of any such dependency in its source. |
| JRN-T192 | *(Architecture)* `JournalRepository::save()`'s transaction body performs no network call or queue publication — proven by the absence of any HTTP/queue/mail/notification dependency in its source, mirroring `JRN-T032`'s existing technique one layer down. |
| JRN-T205 | (M8) `financial_date` and `posted_at` round-trip exactly through a real PostgreSQL row, for both Draft (`posted_at` null) and Posted (`posted_at` a real timestamp). |
| JRN-T206 | (M8) A Financial Date change for an existing `journal_id` is rejected by `save()`, exactly like a TenantId or Journal Line change, and the existing row remains unchanged. |
| JRN-T207 | (M8) `save()` persists `financial_date` exactly and the Draft -> Posted transition sets `posted_at` in the real row; both round-trip exactly through `findById()`. |
| JRN-T208 | (M8) `posted_at`, once written, is identical across repeated `findById()` reads — never silently refreshed to a new "now". |
| JRN-T209 | (M8) `PostingCommandJournalExecutor`'s posting path persists the command's exact Financial Date and sets a real, non-null `posted_at` on the resulting row. |

> **Note on `JRN-T172`–`JRN-T179` and `JRN-005`.** `JRN-005`'s literal text ("Once a Journal is Posted...") scopes immutability to a *Posted* Journal. `JournalRepository` enforces the same no-silent-change guarantee for an existing *Draft* row's Lines too, because the current Journal domain exposes no line-mutation API at all for either state (§9) — persistence must not invent one regardless of lifecycle state. This is a repository-level guarantee stricter than `JRN-005`'s minimum, not a contradiction of it; no new `JRN-NNN` invariant was created for it, since `JRN-005` remains the closest existing statement of intent and AETS-004 §9 already establishes that no line-mutation path exists at any state.
>
> **Note on `JRN-T185`–`JRN-T186` and `JRN-015`.** `JRN-015` ("duplicate-effect prevention") is, at the Posting Command level, an idempotency-key-based guarantee (`JRN-014`) this repository deliberately does not implement (idempotency remains entirely deferred to the future Posting Engine, per this task's explicit scope). What `JournalRepository`'s row-level locking does prove is a structural precondition duplicate-effect prevention depends on: two concurrent writers can never both silently apply conflicting or duplicate changes to the same Journal at the storage layer. This is a partial, repository-level contribution to `JRN-015`'s overall guarantee, not a claim of full duplicate-effect prevention — that claim still requires the Posting Engine's Idempotency Key mechanism.
>
> **Note on the Posting Engine boundary.** No test above treats `save()` itself as a valid Posting Command or as granting posting authorization. A Journal already Posted in memory (constructible today only through `Journal::create(...)->post()`, a real domain operation) may be persisted by `save()` on its first write — this is `JournalRepository` faithfully persisting already-decided domain state, not `JournalRepository` deciding to post anything. No test claims otherwise, and no repository rule restricts insertion to Draft-only Journals, per this task's explicit instruction.

## 22. Performance Expectations

This section states expected behaviors only. It does not define benchmarks, latency targets, or throughput numbers — those belong to a later performance/SLO specification, not this ATS.

- Posting a Journal for one Tenant MUST NOT be blocked by unrelated posting activity belonging to a different Tenant.
- The idempotency check (§11 step 2) is expected to use a bounded, keyed lookup rather than an unbounded scan of Journal history, so its cost does not grow with total Ledger size.
- Balance validation's cost is expected to scale with the number of lines in the Journal being posted, not with the size of the Ledger.
- Concurrent Posting Commands for unrelated commands (different Idempotency Keys, different Draft Journals) are expected not to serialize on a single global lock — only genuinely conflicting commands (same Idempotency Key, or the same Draft Journal) are expected to contend with one another.
- A rejected Posting Command (any failure category, §17) is expected to fail promptly, without first performing any of the atomic persistence work §10 describes.

## 23. Deferred Tests

- **Chart of Accounts, specific business transaction mappings, MyInvois, bank reconciliation, reporting algorithms, tax policy, and AI classification-quality tests** — each depends on a document this ATS's own subject (AETS-004) explicitly excludes (AETS-004 §2.2); deferred to the ATS that will accompany AETS-005, AETS-007, AETS-008, AETS-009, AETS-011, and AETS-013 respectively.
- **Detailed correction-workflow tests** — whether/how many times a Journal may be reversed, approval requirements for a Replacement, and period-close interaction with posting — deferred pending AETS-006 (Posting Rules).
- **Concrete Posting Command schema/API contract tests** — deferred pending AETS-007 (Accounting Commands).
- **Idempotency key derivation-mechanism-specific tests** — this document tests the conceptual contract (§11); the concrete derivation and storage mechanism is deferred pending its own implementation decision (AETS-004 §26).
- **Period-management interaction tests** — a closed Accounting Period rejecting ordinary posting — deferred pending AETS-014 (Period Management).
- **Audit Event schema-specific tests** — this document tests presence and atomicity of the Audit Event (`JRN-T055`); its detailed shape is deferred pending AETS-010 (Audit Trail).
- **Property-based testing library selection** — deferred, not yet chosen for hore.my, exactly as already noted in [ATS-003 §23](ATS-003-Money-Test-Specification.md#23-deferred-tests); §18 here specifies the required properties and generators, not the tooling.
- **Money's general sign-policy deferral** — untouched by this document, exactly as AETS-004 §8 and §26 leave it.

## 24. Changelog

- **1.6.0 (2026-09-06):** Added `JRN-T193`–`JRN-T209` — coverage for M8 (Financial Date & Posting Time Foundation): `JRN-T193`–`JRN-T201` (§8, new "Financial Date and Posted-At (M8)" subsection) cover `Journal`'s own `financialDate`/`postedAt` construction, transition, reconstitution-consistency, and no-self-generation guarantees, plus Reversal/Replacement each carrying their own explicitly-supplied Financial Date; `JRN-T202`–`JRN-T204` (§7, appended to the existing "Persistence Adapter" list) cover `JournalPersistenceAdapter`'s round-trip and consistency-rejection behavior for the two new header fields; `JRN-T205`–`JRN-T209` (§21, appended to the existing "Journal Repository" list) cover real-PostgreSQL round-trip, immutability, and posting-path persistence of both fields. Mapped into the traceability matrix (§5) as two new invariants, `JRN-024` and `JRN-025` (appended — no existing `JRN-NNN` ID renumbered, altered, or removed), matching the corresponding AETS-004 v2.0.0 amendment. No existing test ID (`JRN-T001`–`JRN-T192`) was renumbered, altered, or removed.
- **1.5.0 (2026-09-04):** Added `JRN-T162`–`JRN-T192` (§21, new "Journal Repository" subsection) — the repository-level traceability gap identified and explicitly reported while implementing and reviewing `JournalRepository` (M3-T10): public contract shape (no delete, no raw-row leakage), tenant-scoped lookup, atomic header-plus-lines persistence (including real fault-injected rollback), immutable persisted-Journal protection (TenantId, and every Journal Line field/order/addition/removal, on an existing row of either lifecycle state), the sole Draft -> Posted persisted lifecycle transition (and rejection of every other transition), row-lock-based concurrency (two independent real PostgreSQL connections), exact read-path round-trip (line order, `BIGINT` amount), malformed-state read rejection, and architecture-level absence of any Posting Engine/idempotency/Audit/Outbox/network dependency. Mapped into the existing traceability matrix (§5) under `JRN-001`, `JRN-004`, `JRN-005`, `JRN-006`, `JRN-012`, `JRN-013`, and `JRN-015` — no new `JRN-NNN` invariant was created; two inline notes document where the repository's guarantee is deliberately broader than (`JRN-005`, for Draft-row Line immutability) or only a partial contribution to (`JRN-015`, absent the Posting Engine's Idempotency Key) the invariant it is mapped under. A third inline note reaffirms `JournalRepository` is a persistence primitive, not posting authority — `save()` may persist an already-Posted Journal faithfully, but is never itself a Posting Command. No existing test ID (`JRN-T001`–`JRN-T161`) was renumbered, altered, or removed.
- **1.4.0 (2026-09-04):** Added `JRN-T158`–`JRN-T161` (§21, "Production schema" subsection) — the non-negative-magnitude schema gap identified while hardening `journal_lines.amount` (M3-T9): `CHECK (amount >= 0)` added to the production schema, mirroring the non-negative-only guarantee `Money`/`MinorUnits` already enforce at construction (AETS-003 §9). Zero remains permitted (AETS-004 does not prohibit it, and no minimum monetary value is invented); a positive `BIGINT` magnitude remains accepted; a negative `amount` is now rejected by the database itself; and Direction remains the sole Debit/Credit polarity representation the database permits — no second, sign-encoded representation via `amount` exists. Mapped into the existing traceability matrix (§5) under `JRN-007` and `JRN-008` — no new `JRN-NNN` invariant was needed. No existing test ID (`JRN-T001`–`JRN-T157`) was renumbered, altered, or removed.
- **1.3.0 (2026-09-04):** Added `JRN-T134`–`JRN-T157` (§21, new "Production schema" subsection) — the production-schema traceability gap identified while implementing `database/migrations/..._create_journals_and_journal_lines_tables.php` (M3-T9): the real `journals`/`journal_lines` tables now enforce, at the database level itself, `journal_id` uniqueness, Draft/Posted state acceptance and a `CHECK`-constrained rejection of any other value, `tenant_id`/`state`/`amount`/`currency` `NOT NULL`, `(journal_id, line_position)` as the primary key (rejecting a duplicate position within one Journal while permitting the same position across different Journals, and rejecting a negative position via `CHECK`), a `CHECK`-constrained Direction, the composite foreign keys `(tenant_id, journal_id) -> journals` and `(tenant_id, account_id) -> accounts` (the latter pair together enforcing that a Journal Line's Account can never belong to a different Tenant than its own Journal, mirroring the same technique already established for Account's own same-Tenant parent integrity, M2-T8.1), exact `BIGINT` precision, absence of any signed-amount or balance-total column, multi-line acceptance, and migration reversibility — every one of the above proven against a real PostgreSQL instance, never SQLite. Mapped into the existing traceability matrix (§5) under `JRN-001`, `JRN-002`, `JRN-003`, `JRN-004`, `JRN-007`, `JRN-008`, `JRN-009`, `JRN-010`, and `JRN-011` — no new `JRN-NNN` invariant was needed; every new ID is a database-level, defense-in-depth mirror of a rule an existing invariant already states. Minimum-two-lines, single-Currency, and exact-balance remain explicitly *not* claimed as schema-enforced (documented inline, §21) — they are aggregate-level facts a single row cannot express, and remain `Journal::create()`/`Journal::reconstitute()`'s sole responsibility. No existing test ID (`JRN-T001`–`JRN-T133`) was renumbered, altered, or removed.
- **1.2.0 (2026-09-04):** Added `JRN-T101`–`JRN-T133` (§7, new "Persistence Adapter" subsection, plus three integration IDs appended to §21) — the persistence-adapter traceability gap identified while implementing `JournalPersistenceAdapter` (M3-T8): Journal header (TenantId/JournalId/state) and Journal Line (AccountId/Money amount/Currency/Direction) mapping and exact round-trip, explicit `line_position` as the adapter-confined stand-in for order (`JournalLine` has no identifier of its own, and none is invented here), line order restored correctly even from out-of-order rows, multi-line round-trip, Draft/Posted reconstruction via `Journal::reconstitute()` (never `create(...)->post()`), rejection of insufficient lines, unbalanced Lines (including a row falsely claiming Posted), and every malformed/unsupported persisted value (TenantId, JournalId, AccountId, Money amount, Currency, Journal state, Direction); absence of any native float, signed-amount field, or balance-total field in either persisted shape; Direction persisted independently from Money; and real-PostgreSQL proof of the full round trip, exact `BIGINT` precision, and state/direction fidelity. Mapped into the existing traceability matrix (§5) under `JRN-001`, `JRN-002`, `JRN-003`, `JRN-004`, `JRN-007`, `JRN-008`, `JRN-009`, `JRN-010`, and `JRN-011` — no new `JRN-NNN` invariant was needed; every new ID is a persistence-layer mirror of an already-settled domain guarantee. A genuinely mixed-Currency adapter integration test remains deferred, documented inline (§7), since `Currency` currently supports only `MYR` and no second Currency is invented here to force the scenario. No existing test ID (`JRN-T001`–`JRN-T100`) was renumbered, altered, or removed.
- **1.1.0 (2026-09-04):** Added `JRN-T086`–`JRN-T100` (§7, new "Reconstitution" subsection) — the reconstitution traceability gap identified while implementing `Journal::reconstitute()` (M3-T7): exact TenantId/JournalId/Journal-Line-list/line-order restoration; Draft and Posted state each restorable exactly; reconstituting a Posted Journal never calls `post()` and never throws the "already posted" failure `post()` itself raises; the minimum-two-lines, single-Currency, and exact-balance requirements all still enforced on reconstitution, including when the supplied state claims Posted; identity equality unaffected; and no posting/Audit/Outbox/idempotency side effect of any kind. Mapped into the existing traceability matrix (§5) under `JRN-001`, `JRN-002`, `JRN-003`, `JRN-004`, `JRN-007`, and `JRN-011` — no new `JRN-NNN` invariant was needed; reconstitution re-uses the same financial invariants `create()` already proves, restoring only the lifecycle state as an additional supplied fact. No existing test ID (`JRN-T001`–`JRN-T085`) was renumbered, altered, or removed.
- **1.0.0 (2026-09-04):** Initial creation. Reviewed and marked `Active`. `JRN-002`'s minimum-line-count requirement (at least two Journal Lines) is confirmed final for the current specification baseline; the drafting-stage note flagging it for Founder confirmation is resolved and removed from both this document and [AETS-004](../AETS-004-Journal-Posting-Model.md). No test ID was added, removed, or renumbered.
