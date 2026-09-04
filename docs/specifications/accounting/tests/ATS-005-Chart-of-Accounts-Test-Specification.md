# ATS-005: Chart of Accounts Test Specification

- Status: Active
- Version: 1.1.0
- Effective date: 2026-09-04
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](../AETS-000.md), [AETS-001](../AETS-001-Accounting-Terminology.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-003](../AETS-003-Money-Specification.md), [AETS-004](../AETS-004-Journal-Posting-Model.md), [AETS-005](../AETS-005-Chart-of-Accounts.md); [ADR-0001](../../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../../adr/0004-financial-integrity-principles.md)

## 1. Purpose

This document is the normative Accounting Test Specification (ATS) proving compliance with [AETS-005: Chart of Accounts & Account Taxonomy](../AETS-005-Chart-of-Accounts.md). It exists so that Account domain implementation has a precise, testable, traceable target before any code is written — exactly the coverage AETS-005 §24 said a future Chart of Accounts ATS must provide.

Every test defined here is identified by a stable ID (`COA-T001`–`COA-T097`) and traced to the `COA-NNN` invariant(s) it proves (§5). This document does not implement any test; it specifies what must be proven, at what level, and with what data, so that an implementer or an automated agent can build the actual test suite against it.

## 2. Scope

### 2.1 In scope

- Test-level classification (unit, hierarchy, property-based, golden, security) for every `COA-NNN` invariant and every MUST-level requirement in AETS-005.
- A complete traceability matrix from `COA-001`–`COA-020` to test IDs.
- Concrete test cases for Account construction, Account Type, Normal Balance, Account Code, Account Name, hierarchy, posting eligibility, Account status, System Accounts, User-Created Accounts, tenant isolation, lifecycle, and Journal Line integration.
- Generator definitions for property-based testing.
- Qualitative persistence expectations for future integration tests (behavior only, no schema design).

### 2.2 Out of scope

- Any implementation code, test framework configuration, CI wiring, or Account Code grammar/numbering scheme — this document specifies tests, it does not write them or lock a numbering convention (AETS-005 §8, §25).
- Selecting a property-based testing library — not yet chosen for hore.my, exactly as already noted in [ATS-003 §23](ATS-003-Money-Test-Specification.md#23-deferred-tests) and [ATS-004 §23](ATS-004-Journal-Posting-Test-Specification.md#23-deferred-tests); §20 here specifies the required properties and generators, not the tooling.
- Anything AETS-005 itself defers or excludes (AETS-005 §2.2, §25): Posting Command schema, business transaction mappings, tax rules, MyInvois, bank reconciliation, reporting algorithms, inventory, payroll, multi-entity, multi-currency, a full default Chart of Accounts, and the Account Code change workflow/audit mechanics. Tests that would require any of these are listed as deferred (§25), not designed around a guess.
- Journal/Posting behavior already specified and tested by [AETS-004](../AETS-004-Journal-Posting-Model.md) / [ATS-004](ATS-004-Journal-Posting-Test-Specification.md) — this document tests only the Account-side contract AETS-005 adds, not Journal balance, atomicity, or idempotency again.
- Money's own construction, arithmetic, and persistence tests — already fully specified by [ATS-003](ATS-003-Money-Test-Specification.md).

## 3. Authority

This document is subordinate to [AETS-005](../AETS-005-Chart-of-Accounts.md), which is itself subordinate to [ADR-0004](../../../adr/0004-financial-integrity-principles.md) and the authority hierarchy in [AETS-000 §3](../AETS-000.md#3-authority-hierarchy). Where a test specified here appears to require behavior AETS-005 does not define, that is a defect in this document to be corrected, not a license to invent AETS-005 content through a test (per [AETS-000 §8.4](../AETS-000.md#84-relationship-to-adrs-and-conflict-handling)). No contradiction between this document and AETS-005 was found while drafting it, so AETS-005 was not modified.

## 4. Test Strategy

- **Prove the invariant, not the implementation.** Every test traces to a `COA-NNN` invariant or an explicit AETS-005 requirement — not to incidental implementation detail, mirroring [ATS-003 §4](ATS-003-Money-Test-Specification.md#4-test-philosophy) and [ATS-004 §4](ATS-004-Journal-Posting-Test-Specification.md#4-test-strategy)'s established approach for this series.
- **Exact-or-fail, tested both ways.** Every Account construction, mutation, and posting-eligibility check must produce either a valid result or a typed failure — this ATS tests every reachable outcome explicitly, not only the happy path.
- **No silent behavior is acceptable evidence.** A test that merely checks "no exception was thrown" is insufficient; tests assert the specific expected Account state or the specific expected failure category.
- **Historical integrity is provable, not assumed.** Any test claiming "posted history is unaffected" (`COA-011`, `COA-014`) must verify the Journal Line's observable effect directly after the Account-side change, not merely that the change itself succeeded.
- **Don't invent AETS-005 content.** Where AETS-005 leaves a case open (for example, whether hierarchy siblings must share an Account Type, §12) or explicitly defers it (§25), this document says so rather than picking an answer a test would then silently enforce.

| Level | Purpose | Primary sections |
| --- | --- | --- |
| **Unit** | Isolated construction/validation behavior of an Account, no persistence. | §7–§11, §14–§16 |
| **Hierarchy** | Parent/child construction, cycle detection, tenant boundary. | §12 |
| **Posting-eligibility** | The Account-side checks a Posting Command must perform before accepting a Journal Line. | §13, §19 |
| **Tenant isolation** | Cross-tenant rejection for Account, hierarchy, and Journal Line reference. | §17 |
| **Lifecycle** | Active/Inactive transitions and no-hard-delete guarantees. | §18 |
| **Property-based** | Behavior proven across a generated range of inputs, not fixed examples. | §20 |
| **Golden cases** | Fixed, exact, hand-verified Account examples that must never silently change. | §21 |
| **Security** | Adversarial/boundary input tests. | §22 |
| **Integration** *(future)* | Persistence round-trip and referential-integrity behavior — expectations only here (§23); not designed as concrete tests until an Account persistence adapter exists. | §23 |

## 5. Traceability Matrix

Every `COA-NNN` invariant from [AETS-005 §21](../AETS-005-Chart-of-Accounts.md#21-invariants) maps to at least one test ID below.

| Invariant | Summary | Test IDs |
| --- | --- | --- |
| COA-001 | Tenant ownership | COA-T058, COA-T093 |
| COA-002 | Stable identifier | COA-T002, COA-T093 |
| COA-003 | Tenant-scoped code uniqueness | COA-T024, COA-T025, COA-T055, COA-T073 |
| COA-004 | Exactly one Account Type | COA-T004, COA-T009, COA-T010, COA-T011, COA-T012, COA-T013, COA-T014, COA-T015, COA-T093 |
| COA-005 | Canonical Normal Balance | COA-T005, COA-T016, COA-T017, COA-T018, COA-T019, COA-T020, COA-T021, COA-T054, COA-T070, COA-T090, COA-T093 |
| COA-006 | No hierarchy cycles | COA-T034, COA-T035, COA-T071, COA-T072, COA-T083 |
| COA-007 | Same-tenant parent/child | COA-T033, COA-T037, COA-T059 |
| COA-008 | Posting only to posting-eligible accounts | COA-T040, COA-T042, COA-T067, COA-T085 |
| COA-009 | Inactive account rejects new posting | COA-T041, COA-T066, COA-T084, COA-T095 |
| COA-010 | Non-posting/group account rejects posting | COA-T039, COA-T042, COA-T067, COA-T085 |
| COA-011 | Posted history survives deactivation | COA-T043, COA-T064, COA-T075 |
| COA-012 | No authoritative mutable balance on Account | COA-T008, COA-T069, COA-T074 |
| COA-013 | System account protection | COA-T048, COA-T049, COA-T050 |
| COA-014 | No hard delete after posted reference | COA-T051, COA-T063 |
| COA-015 | Journal Line/Journal tenant match | COA-T060, COA-T068, COA-T086 |
| COA-016 | System account tenant immutability | COA-T052, COA-T061 |
| COA-017 | Account name presence | COA-T003 |
| COA-018 | Explicit posting-eligibility state | COA-T006, COA-T093 |
| COA-019 | Explicit active/inactive state | COA-T007, COA-T044, COA-T091, COA-T092, COA-T094, COA-T096, COA-T097 |
| COA-020 | Account Code is not a raw identifier | COA-T026, COA-T087 |

## 6. Test Data Strategy

- **Abstract identifiers only.** Account Codes, Names, Tenant identifiers, and Journal/Actor references used throughout this document are synthetic placeholders (`1000`, `TENANT-A`, `ACTOR-1`, and so on) — never a final Chart of Accounts code or real business data, consistent with [AETS-005 §23](../AETS-005-Chart-of-Accounts.md#23-examples-informative)'s own framing.
- **Two-Tenant minimum for isolation tests.** Every tenant-isolation test (§17) requires at least two distinct, otherwise-valid Tenants, mirroring [ATS-004 §6](ATS-004-Journal-Posting-Test-Specification.md#6-test-data-strategy)'s established convention for this series.
- **Reuse AETS-004's Journal fixtures where posting is exercised.** Journal Integration tests (§19) reuse the same abstract Journal/Posting Command shape [ATS-004](ATS-004-Journal-Posting-Test-Specification.md) already establishes (e.g. `JRN-T069`'s cash-sale pattern) rather than inventing a parallel one.
- **Hierarchy generators span depth and branching.** Hierarchy tests (§12, §20) require generated trees of varying depth (flat, one level, four or more levels) and branching factor, plus deliberately cycle-injected variants for negative cases.
- **System vs. User-Created Account fixtures are distinct.** Every System Account test (§15) uses an Account explicitly constructed via the deterministic-seeding path this document assumes exists (§6 of AETS-005), never a User-Created Account relabeled for the test.
- **No real persistence required for this document's own tests.** Every test in §7–§22 is specified at the domain/unit level; only §23's expectations require a real database, and even those are not concrete tests here (§2.2).

## 7. Account Construction Tests

| ID | Test |
| --- | --- |
| COA-T001 | An Account can be validly constructed with a Tenant, Account Code, Name, Account Type, posting-eligibility state, and Active/Inactive state all present. |
| COA-T002 | Every Account is assigned a stable, opaque identifier at creation, immutable for its lifetime. |
| COA-T003 | An Account cannot be constructed with an empty Name. |
| COA-T004 | An Account cannot be constructed without exactly one Account Type. |
| COA-T005 | An Account's Normal Balance, once constructed, is exactly the single canonical value its Account Type derives — never independently suppliable. |
| COA-T006 | An Account cannot be constructed with an undefined posting-eligibility state. |
| COA-T007 | An Account cannot be constructed with an undefined Active/Inactive state; it defaults to Active. |
| COA-T008 | An Account's public shape exposes no mutable authoritative balance field or accessor, at construction or thereafter. |

## 8. Account Type Tests

| ID | Test |
| --- | --- |
| COA-T009 | Asset is a valid, constructible Account Type. |
| COA-T010 | Liability is a valid, constructible Account Type. |
| COA-T011 | Equity is a valid, constructible Account Type. |
| COA-T012 | Revenue is a valid, constructible Account Type. |
| COA-T013 | Expense is a valid, constructible Account Type. |
| COA-T014 | An unsupported Account Type (any value outside the five canonical types) is rejected at construction. |
| COA-T015 | Account Type is immutable — no operation changes an existing Account's Type after creation. |

## 9. Normal Balance Tests

| ID | Test |
| --- | --- |
| COA-T016 | Asset → Debit. |
| COA-T017 | Expense → Debit. |
| COA-T018 | Liability → Credit. |
| COA-T019 | Equity → Credit. |
| COA-T020 | Revenue → Credit. |
| COA-T021 | Constructing an Account with a Normal Balance inconsistent with its Account Type is rejected — Normal Balance cannot be independently overridden. |
| COA-T022 | Normal Balance does not replace Journal Line Direction: a Journal Line posted with a Direction opposite its Account's Normal Balance is still accepted at the Account-reference level — Normal Balance is never consulted to infer, default, or validate away the Line's own explicit Direction. |

## 10. Account Code Tests

| ID | Test |
| --- | --- |
| COA-T023 | An Account cannot be constructed without an Account Code. |
| COA-T024 | Account Code uniqueness is enforced within a Tenant — a second Account in the same Tenant with a duplicate Code is rejected. |
| COA-T025 | The same Account Code is allowed across two different Tenants. |
| COA-T026 | An Account Code is never, and is never derived from, a raw database identifier exposed directly as the code. |
| COA-T027 | A System Account's Code is immutable — no operation changes it once assigned. |
| COA-T028 | A User-Created Account's Code MAY change, provided the result still satisfies Tenant-scoped uniqueness; a change to a colliding Code is rejected. |

## 11. Account Name Tests

| ID | Test |
| --- | --- |
| COA-T029 | An Account's Name MAY be changed at any time, for a System or a User-Created Account, provided the result remains non-empty. |
| COA-T030 | An attempted rename to an empty Name is rejected. |
| COA-T031 | Renaming an Account does not alter the observable effect of any Journal Line already posted against it. |

## 12. Hierarchy Tests

| ID | Test |
| --- | --- |
| COA-T032 | An Account MAY be constructed with no parent — hierarchy is optional. |
| COA-T033 | An Account MAY be constructed with exactly one parent Account belonging to the same Tenant. |
| COA-T034 | A direct self-cycle (an Account specified as its own parent) is rejected. |
| COA-T035 | An indirect, transitive cycle (for example, A → B → C → A) is rejected. |
| COA-T036 | A deep, valid, cycle-free hierarchy (four or more levels) is accepted. |
| COA-T037 | A parent/child relationship across two different Tenants is rejected. |
| COA-T038 | Hierarchy is not required for any Account — a flat Chart of Accounts with no parent/child relationships at all is valid. |
| COA-T039 | An Account used purely as a hierarchy grouping node, marked non-posting, rejects a Journal Line reference (group/non-posting behavior exercised in a hierarchy context; see also §13). |

## 13. Posting Eligibility Tests

| ID | Test |
| --- | --- |
| COA-T040 | An Active, posting-eligible Account accepts a Journal Line reference. |
| COA-T041 | An Inactive Account rejects a new Journal Line reference, even if otherwise posting-eligible. |
| COA-T042 | A non-posting/group Account rejects a Journal Line reference, regardless of its Active/Inactive state. |
| COA-T043 | A Journal Line already posted against an Account remains valid and observable after that Account is later made Inactive. |

## 14. Account Status Tests

| ID | Test |
| --- | --- |
| COA-T044 | An Account defaults to Active at creation. |
| COA-T045 | An Account MAY transition Active → Inactive. |
| COA-T046 | An Account MAY transition Inactive → Active (reactivation). |
| COA-T047 | An Inactive Account retains its identifier, Code, Type, Normal Balance, and posting history unchanged. |

## 15. System Account Tests

| ID | Test |
| --- | --- |
| COA-T048 | A System Account's Code is not altered by any user-facing operation. |
| COA-T049 | A System Account's Account Type is not altered by any user-facing operation. |
| COA-T050 | A System Account's Normal Balance is not altered by any user-facing operation. |
| COA-T051 | A System Account is not hard-deleted, whether or not it has ever been posted to. |
| COA-T052 | A System Account is not reassigned to a different Tenant. |

## 16. User-Created Account Tests

| ID | Test |
| --- | --- |
| COA-T053 | A User-Created Account can be created within a Tenant through the ordinary account-creation path. |
| COA-T054 | A User-Created Account must comply with the canonical Account Type/Normal-Balance pairing rules (§8, §9 of this document). |
| COA-T055 | A User-Created Account must use a Tenant-scoped unique Account Code. |
| COA-T056 | A User-Created Account cannot be created with a Code colliding with a System Account's Code in the same Tenant. |
| COA-T057 | A User-Created Account operation cannot retype, re-code, or otherwise mutate a System Account. |

## 17. Tenant Isolation Tests

| ID | Test |
| --- | --- |
| COA-T058 | An Account belongs to exactly one Tenant; an Account with no Tenant is not constructible. |
| COA-T059 | A parent/child hierarchy relationship across two different Tenants is rejected (tenant-isolation angle of `COA-T037`). |
| COA-T060 | A Posting Command referencing an Account of a different Tenant than the Journal itself is rejected before any persistent effect. |
| COA-T061 | An attempted System Account tenant reassignment is rejected. |

## 18. Lifecycle Tests

| ID | Test |
| --- | --- |
| COA-T062 | Active → Inactive transition is supported and observable. |
| COA-T063 | An Account referenced by any posted Journal Line is not hard-deleted. |
| COA-T064 | Deactivating an Account does not alter the historical effect of any Journal Line already posted against it. |
| COA-T091 | An Active Account can be deactivated — the operation succeeds. |
| COA-T092 | `deactivate()` returns a new Account instance, distinct from the one it was called on. |
| COA-T093 | Deactivation preserves TenantId, AccountId, AccountCode, AccountName, AccountType, NormalBalance, and the configured posting-eligibility state — only the Active/Inactive state (and the effective posting-allowed answer derived from it) changes. |
| COA-T094 | A deactivated Account reports Inactive. |
| COA-T095 | An Inactive Account reports posting not allowed, even when its own posting-eligibility configuration remains posting-eligible. |
| COA-T096 | Repeated deactivation is deterministic: deactivating an already-Inactive Account produces another Account equal in every observable respect. |
| COA-T097 | Deactivation does not mutate the original Account — the original remains Active (and every other field unchanged) after `deactivate()` is called on it. |

## 19. Journal Integration Tests

| ID | Test |
| --- | --- |
| COA-T065 | A Journal Line references an Account by its stable identifier — never by Account Code or Name. |
| COA-T066 | A Posting Command rejects a Journal Line whose referenced Account is not Active. |
| COA-T067 | A Posting Command rejects a Journal Line whose referenced Account is not posting-eligible. |
| COA-T068 | A Posting Command rejects a Journal Line whose referenced Account's Tenant does not match the Journal's own Tenant. |
| COA-T069 | An Account's public shape carries no field aggregating, caching, or otherwise holding Journal Line amounts. |

## 20. Property-Based Tests

### 20.1 Generators

| Generator | Produces |
| --- | --- |
| **Valid Accounts** | Generated combinations of Tenant, Account Type (one of the five canonical types), Code, Name, posting-eligibility, and Active/Inactive state, all internally consistent per §7–§11. |
| **Hierarchy shapes** | Generated Account trees of varying depth (0 to 5+ levels) and branching factor, within one Tenant, guaranteed cycle-free unless a cycle is deliberately injected for a negative-case variant. |
| **Cycle-injected hierarchies** | A valid generated hierarchy shape with one additional edge added that creates a direct or transitive cycle. |
| **Tenant pairs** | Two distinct, otherwise-valid Tenants, each with its own generated Accounts, used for cross-tenant negative tests. |
| **Posting-then-deactivate sequences** | A generated valid Journal (reusing [ATS-004](ATS-004-Journal-Posting-Test-Specification.md#181-generators)'s Posting Command generators) posted against a generated Account, followed by a generated deactivation of that Account. |

### 20.2 Properties

| ID | Property |
| --- | --- |
| COA-T070 | For any generated Account Type: deriving Normal Balance always yields exactly one valid value (Debit or Credit), matching the canonical table (§9). |
| COA-T071 | For any generated hierarchy shape (no injected cycle): the hierarchy is always found cycle-free. |
| COA-T072 | For any generated cycle-free hierarchy: adding any edge that would create a direct or transitive cycle is always rejected. |
| COA-T073 | For any generated set of Accounts within one Tenant: Account Code uniqueness always holds — no two Accounts in the same Tenant ever share a Code. |
| COA-T074 | For any generated valid Account: it never contains an authoritative monetary balance field. |
| COA-T075 | For any generated posting-then-deactivate sequence: the historical posted effect is never changed by the deactivation. |

## 21. Golden Test Cases

**These examples mirror [AETS-005 §23](../AETS-005-Chart-of-Accounts.md#23-examples-informative)'s own informative examples, made concrete as data-driven test cases. They remain informative only — this document does not lock these names or codes as the final default Chart of Accounts.**

| ID | Case | Expected |
| --- | --- | --- |
| COA-T076 | Cash / Bank | Account Type Asset; Normal Balance Debit; constructs and posts successfully. |
| COA-T077 | Accounts Receivable | Account Type Asset; Normal Balance Debit; constructs and posts successfully. |
| COA-T078 | Accounts Payable | Account Type Liability; Normal Balance Credit; constructs and posts successfully. |
| COA-T079 | Owner Capital | Account Type Equity; Normal Balance Credit; constructs and posts successfully. |
| COA-T080 | Sales Revenue | Account Type Revenue; Normal Balance Credit; constructs and posts successfully. |
| COA-T081 | General Expense | Account Type Expense; Normal Balance Debit; constructs and posts successfully. |
| COA-T082 | Valid parent/group account | A non-posting "Current Assets" header Account with Cash / Bank and Accounts Receivable as same-Tenant children; constructs successfully; the header itself rejects a Journal Line (`COA-T039`). |
| COA-T083 | Rejected hierarchy cycle | An attempt to set an Account's parent to one of its own descendants is rejected. |
| COA-T084 | Rejected inactive posting | A Journal Line referencing an Inactive Account is rejected. |
| COA-T085 | Rejected non-posting account | A Journal Line referencing the "Current Assets" header from `COA-T082` is rejected. |
| COA-T086 | Rejected cross-tenant account reference | A Posting Command for `TENANT-A`'s Journal referencing an Account belonging to `TENANT-B` is rejected before any persistent effect. |

## 22. Security / Invalid Input Tests

| ID | Test |
| --- | --- |
| COA-T087 | An adversarially long or malformed Account Code is rejected via a bounded, deterministic check, mirroring the input-hygiene pattern already established for Money ([AETS-003 §18](../AETS-003-Money-Specification.md#18-security-and-validation)). |
| COA-T088 | An adversarially long or malformed Account Name is rejected via a bounded, deterministic check. |
| COA-T089 | An Account Type value outside the five canonical types (an arbitrary string or other unsupported value) is rejected at construction, not silently coerced to a valid type. |
| COA-T090 | An attempt to directly set or override an Account's Normal Balance independent of its Account Type, bypassing the canonical derivation, is rejected. |

## 23. Persistence Expectations

This section states what a future Account persistence integration test suite MUST prove, once an Account persistence adapter exists; it does not design that adapter's schema (AETS-005 explicitly defers this, §2.2, §25 — consistent with how [ATS-003 §15](ATS-003-Money-Test-Specification.md#15-persistence-adapter-contract-tests) and [ATS-004 §21](ATS-004-Journal-Posting-Test-Specification.md#21-integration-tests) treat their own persistence layers).

A future Account persistence integration suite MUST prove:

- **Tenant-scoped code uniqueness is enforced** at the persistence layer, not only in application-level validation — a concurrent attempt to persist two Accounts with the same Code in the same Tenant MUST result in exactly one succeeding.
- **Stable identifier round-trip** — an Account's identifier is unchanged by a write-then-read cycle, exactly as [ATS-003](ATS-003-Money-Test-Specification.md#15-persistence-adapter-contract-tests) already requires for Money's own persisted identity.
- **Account Type / status / posting-eligibility round-trip** — writing and reading back an Account reproduces its exact Type, Active/Inactive state, and posting-eligibility state, with no narrowing, defaulting, or silent coercion.
- **Hierarchy reference integrity** — a persisted parent/child reference resolves to an existing Account of the same Tenant; no persisted hierarchy edge references a nonexistent Account or an Account of a different Tenant.
- **No hard delete of a referenced Account** — an attempt to delete an Account row referenced by any posted Journal Line fails at the persistence layer, not merely at the application layer, mirroring the defense-in-depth AETS-002 invariant 4 already implies.
- **No monetary balance column acts as authoritative ledger state** — the persisted Account representation carries no column whose value is treated as the source of truth for a balance; any balance-shaped column, if one exists at all, is provably a cache/projection, rebuildable from posted Journals (AETS-002 invariant 5).
- **Real PostgreSQL, not SQLite, is required to prove any of the above with real integrity/constraint semantics** — consistent with the precedent already established for Money's Persistence Adapter and for [ATS-004 §21](ATS-004-Journal-Posting-Test-Specification.md#21-integration-tests).

## 24. Exit Criteria

Account/Chart of Accounts implementation **cannot** be considered complete unless, at minimum:

- All required `COA-T` tests (§7–§19, §21–§22) pass.
- All property-based tests (§20) pass.
- All golden cases (§21) pass, exactly, with no tolerance.
- Every tenant-isolation test (§17) passes — no cross-tenant Account access, hierarchy link, or Journal Line reference succeeds under any tested scenario.
- No hard delete of a System Account, or of any Account referenced by a posted Journal Line, succeeds under any tested scenario.
- No authoritative mutable balance is observable on any Account under any tested scenario.
- Static analysis (Larastan/PHPStan, level 9) passes with zero errors on all Account-related code.

## 25. Deferred Tests

- **A full default Chart of Accounts** — content-specific tests (which Accounts a new Tenant receives) — deferred; AETS-005 itself does not design this content (§2.2, §25).
- **Account Code grammar/numbering convention tests** — deferred; only tenant-scoped uniqueness and the "not a raw identifier" rule are tested now (§10), consistent with AETS-005 §8 not locking a numbering scheme.
- **Posting Command schema tests, business transaction mapping tests** — deferred to the ATS accompanying AETS-007 (Accounting Commands).
- **Tax rule and MyInvois tests** — deferred; none currently required by any project source.
- **Bank reconciliation, reporting/Trial Balance algorithm tests** — deferred to the ATS accompanying AETS-008 and AETS-009 respectively.
- **Inventory, payroll, multi-entity, multi-currency tests** — out of MVP scope entirely.
- **Account Code change workflow/audit mechanics tests** for a User-Created Account — this document tests only that the change is forward-only and re-validated for uniqueness (§10); the concrete Actor-confirmation and audit-event mechanism is deferred, consistent with AETS-005 §25.
- **Whether hierarchy parent/child must share the same Account Type** — not tested, since AETS-005 §12 leaves this an open, non-normative observation rather than a locked rule.
- **Concrete Account persistence integration tests** — §23 states required coverage as expectations only; the concrete tests await an actual persistence adapter, mirroring how AETS-005 itself defers Account persistence/schema design entirely.
- **Property-based testing library selection** — deferred, not yet chosen for hore.my, exactly as already noted in [ATS-003 §23](ATS-003-Money-Test-Specification.md#23-deferred-tests) and [ATS-004 §23](ATS-004-Journal-Posting-Test-Specification.md#23-deferred-tests).

## 26. Change Governance

This document follows [AETS-000](../AETS-000.md)'s governance rules in full — it does not restate them: lifecycle ([AETS-000 §8.3](../AETS-000.md#83-lifecycle)), review requirements ([AETS-000 §8.2](../AETS-000.md#82-ownership-and-review)), and versioning ([AETS-000 §9.1](../AETS-000.md#91-per-document-version)) all apply unchanged. This document is `Active` and governs current implementation.

## Changelog

- **1.1.0 (2026-09-04):** Added `COA-T091`–`COA-T097` (§18, Lifecycle Tests) — the `Account::deactivate()` coverage gap identified while implementing the Account aggregate (M2-T5.1): deactivation succeeds, returns a new instance, preserves every field but Active state, results in Inactive, causes posting-not-allowed even when posting-eligible is still configured true, is deterministic under repetition, and never mutates the original. Mapped into the existing traceability matrix (§5) under `COA-001`, `COA-002`, `COA-004`, `COA-005`, `COA-009`, `COA-018`, and `COA-019` — no new `COA-NNN` invariant was needed. No existing test ID (`COA-T001`–`COA-T090`) was renumbered, altered, or removed.
- **1.0.0 (2026-09-04):** Initial creation. Reviewed and marked `Active`. No `COA-T` test ID, traceability mapping, or any other content changed.
