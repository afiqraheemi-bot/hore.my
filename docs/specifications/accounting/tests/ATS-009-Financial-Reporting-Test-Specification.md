# ATS-009: Financial Reporting Test Specification

- Status: Active
- Version: 1.5.0
- Effective date: 2026-09-07
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](../AETS-000.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-004](../AETS-004-Journal-Posting-Model.md), [AETS-005](../AETS-005-Chart-of-Accounts.md), [AETS-007](../AETS-007-Posting-Command.md), [AETS-009](../AETS-009-Financial-Reporting.md), [AETS-010](../AETS-010-Audit-Trail-Evidence-Linkage.md); [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0007](../../../adr/0007-money-representation-strategy.md)

## 1. Purpose

This document is the normative Accounting Test Specification (ATS) proving compliance with [AETS-009: Financial Reporting](../AETS-009-Financial-Reporting.md), fulfilling the exact coverage AETS-009 §13 names as required and explicitly leaves for this document to write. Every test defined here is identified by a stable ID (`RPT-T001`–`RPT-T049`) and traced to the `RPT-NNN` invariant(s) it proves (§5).

Like [ATS-010](ATS-010-Audit-Trail-Test-Specification.md), this document was authored alongside — immediately after — AETS-009's implementation (M10), not before it: every test ID below traces to a concrete, already-passing test in the current suite, not a future target. The traceability matrix in §5 can therefore be verified directly against the repository rather than taken on faith.

## 2. Scope

### 2.1 In scope

- A complete traceability matrix from `RPT-001`–`RPT-013` to test IDs.
- Concrete test cases for Trial Balance, Profit & Loss, Balance Sheet, General Ledger drill-down, Evidence Index, and, as of v1.1.0, the Debtors/Aging Report — the reports AETS-009 §2.1 defines.
- Golden-dataset reconciliation against real Expense (M7) and Income (M9) postings, Draft-exclusion, tenant isolation, `financialDate`-vs-`postedAt`, M5 correction-chain robustness, General Ledger traceability, Evidence Index accuracy, and a no-write architecture proof — the exact list AETS-009 §13 requires.
- **As of v1.1.0:** Aging completeness and bucket determinism proof, per AETS-009 §13's own new bullet.
- **As of v1.5.0:** Compliance Pack fidelity proof, per AETS-009 §13's own new bullet — unlike CSV export (§2.2 below), the ZIP-bundling code path itself (`ZipResponseBuilder`, `ReportingController::compliancePack()`) is genuinely new and untested elsewhere, so it is given real coverage here rather than treated as a zero-new-code-path reshaping.

### 2.2 Out of scope

- Cash Flow, Reconciliation Report, PDF/XLSX export, and formal reproducibility testing — each deferred by AETS-009 itself (§15), pending a prerequisite module that does not yet exist. No test below claims coverage of any of these.
- **CSV export tests** (AETS-009 §18) — a presentation-layer reshaping of an already-tested report's own output; not separately tested by this document, consistent with AETS-009 §18 itself stating CSV rendering introduces no new business logic to prove.
- **`ZipResponseBuilder`'s own generic ZIP-building correctness** (empty-archive handling, content-type/disposition headers) — a domain-agnostic `Http\Support` concern with its own dedicated unit test class (`Tests\Unit\Http\Support\ZipResponseBuilderTest`), mirroring how `CsvResponseBuilderTest` is not part of this document's own scope either.
- ~~A dedicated cross-tenant isolation test for the Aging Report~~ — resolved as of v1.4.0; see `RPT-T048` (§6.8) and `RPT-002`'s own traceability row (§5).
- Money, Journal, Chart of Accounts, Posting Command, and Audit Trail's own construction/validation tests — already fully specified by [ATS-003](ATS-003-Money-Test-Specification.md), [ATS-004](ATS-004-Journal-Posting-Test-Specification.md), [ATS-005](ATS-005-Chart-of-Accounts-Test-Specification.md), [ATS-007](ATS-007-Posting-Pipeline-Test-Specification.md), and [ATS-010](ATS-010-Audit-Trail-Test-Specification.md).
- Period Management's interaction with reporting — deferred alongside AETS-009's own deferral (§15), to a future AETS-014 test specification.

## 3. Authority

This document is subordinate to [AETS-009](../AETS-009-Financial-Reporting.md) and the authority hierarchy in [AETS-000 §3](../AETS-000.md#3-authority-hierarchy). No contradiction between this document and AETS-009 was found while drafting it.

## 4. Test Strategy

- **Prove the invariant, not the implementation.** Every test traces to an `RPT-NNN` invariant.
- **Real PostgreSQL for every integration-level claim.** Golden-dataset reconciliation, Draft-exclusion, tenant isolation, `financialDate`-vs-`postedAt`, correction-chain robustness, General Ledger traceability, and Evidence Index accuracy all run against a real PostgreSQL instance — never SQLite — mirroring [ATS-004 §4](ATS-004-Journal-Posting-Test-Specification.md#4-test-strategy)'s established rule, since these properties are exactly the ones a mocked or in-memory substitute cannot prove.
- **Reconciliation, not fault-injection.** Unlike ATS-004/ATS-007/ATS-010, this document specifies no fault-injection or concurrency tests: AETS-009's own Test Strategy (§13) states this document "has no write path to fault-inject against" — Financial Reporting is read-only by construction (`RPT-006`), so its correctness is proven by reconciling computed totals against hand-verified expected values, not by proving transactional rollback behavior that does not apply here.
- **Algebraic edge cases, not only the expected case.** Every net-balance computation (`NetBalance`, `ProfitAndLossStatement.netIncomeBalance()`, `BalanceSheet.isBalanced()`) is tested against the case where an Account or Account group nets to its *non-normal* Direction (e.g. a Revenue Account netting Debit after an oversized Reversal) — not only the case where every group behaves as its Normal Balance would suggest — since this is exactly the case a naive "compare two already-netted magnitudes" implementation would silently mishandle (see [AETS-009](../AETS-009-Financial-Reporting.md) §7's own documented algebra).

## 5. Traceability matrix

| Invariant | Test IDs |
| --- | --- |
| RPT-001 | RPT-T027 |
| RPT-002 | RPT-T028 (§6–§10's reports), RPT-T048 (Aging Report specifically, closing the gap §15 of AETS-009 named until 2026-09-11) |
| RPT-003 | *(Not independently testable — see §7.)* |
| RPT-004 | Every test in §6 — every value in every test is a `Money` object; no test constructs or asserts against a native float or numeric string arithmetic result. RPT-T020 is the critical proof: a naive "compare two already-netted magnitudes" implementation would silently compute the wrong Net Income for exactly the case it covers. |
| RPT-005 | RPT-T029 |
| RPT-006 | RPT-T023 |
| RPT-007 | RPT-T008, RPT-T009, RPT-T010, RPT-T011, RPT-T024 |
| RPT-008 | RPT-T012, RPT-T013, RPT-T014, RPT-T015, RPT-T016, RPT-T026 |
| RPT-009 | RPT-T024, RPT-T025, RPT-T026 (the three golden-dataset reconciliation tests share one underlying dataset, proving Trial Balance, Profit & Loss, and Balance Sheet agree with each other, not merely each internally consistent) |
| RPT-010 | RPT-T031 |
| RPT-011 | RPT-T032 |
| RPT-012 | RPT-T035, RPT-T036, RPT-T040, RPT-T041, RPT-T045 |
| RPT-013 | RPT-T033, RPT-T034, RPT-T037, RPT-T038 |
| RPT-014 | RPT-T046, RPT-T047 |
| RPT-015 | RPT-T049 |

## 6. Test cases

### 6.1 Domain — `NetBalance`

| Test ID | Description | Level |
| --- | --- | --- |
| RPT-T001 | Debit total larger than Credit total nets to the Debit direction, amount equal to the difference. | Unit |
| RPT-T002 | Credit total larger than Debit total nets to the Credit direction, amount equal to the difference. | Unit |
| RPT-T003 | Equal Debit and Credit totals net to zero with **no** Direction (never inferred from an Account's Normal Balance). | Unit |
| RPT-T004 | Both totals zero nets to zero with no Direction. | Unit |

### 6.2 Domain — `AccountBalance`

| Test ID | Description | Level |
| --- | --- | --- |
| RPT-T005 | An `AccountBalance` preserves its Account ID and Account Type exactly. | Unit |
| RPT-T006 | An `AccountBalance` computes its own `NetBalance` correctly from its total Debit/Credit. | Unit |
| RPT-T007 | An Account with zero activity has a zero `NetBalance`. | Unit |

### 6.3 Domain — `TrialBalance`

| Test ID | Description | Level |
| --- | --- | --- |
| RPT-T008 | A balanced set of lines reports `isBalanced() === true`, with `totalDebit()`/`totalCredit()` each the raw sum across every line (`RPT-007`). | Unit |
| RPT-T009 | A deliberately unbalanced set of lines reports `isBalanced() === false` — proving the check can detect a genuine defect, not merely confirm the expected case (`RPT-007`). | Unit |
| RPT-T010 | An Account with zero activity still appears as its own line, contributing zero. | Unit |
| RPT-T011 | An empty Trial Balance is trivially balanced (`0.00 === 0.00`). | Unit |

### 6.4 Domain — `BalanceSheet`

| Test ID | Description | Level |
| --- | --- | --- |
| RPT-T012 | The AETS-009 §14 golden example (one Expense, one Income) balances with a Credit-direction (profit) Cumulative Net Income line (`RPT-008`). | Unit |
| RPT-T013 | A Balance Sheet with real Asset, Liability, and Equity Accounts (pure capital funding, zero Cumulative Net Income) balances (`RPT-008`). | Unit |
| RPT-T014 | A deliberately unbalanced sheet reports `isBalanced() === false` (`RPT-008`). | Unit |
| RPT-T015 | A Debit-direction (loss) Cumulative Net Income line folds correctly into the Debit pool and the sheet still balances (`RPT-008`). | Unit |
| RPT-T016 | A zero Cumulative Net Income line does not disturb an otherwise-balanced sheet. | Unit |

### 6.5 Domain — `ProfitAndLossStatement`

| Test ID | Description | Level |
| --- | --- | --- |
| RPT-T017 | Revenue exceeding Expense computes the correct Net Income and reports `isProfit() === true`. | Unit |
| RPT-T018 | Expense exceeding Revenue computes the correct Net Income and reports `isProfit() === false`. | Unit |
| RPT-T019 | Exact break-even (`Net Income === 0.00`) reports `isProfit() === true` (a Credit-or-zero direction is profit, per `NetBalance`'s own zero-carries-no-Direction rule applied at the statement level). | Unit |
| RPT-T020 | A Revenue Account netting **Debit** direction (an oversized Reversal) still computes the correct combined Net Income when combined with a normal Expense Account — the algebraic edge case §4 requires. | Unit |
| RPT-T021 | Multiple Revenue and Expense Accounts aggregate to the correct combined totals and Net Income. | Unit |
| RPT-T022 | No activity at all is break-even (`0.00`, profit). | Unit |

### 6.6 Architecture — no-write proof

| Test ID | Description | Level |
| --- | --- | --- |
| RPT-T023 | None of `AccountBalanceAggregator`, `TrialBalanceQuery`, `ProfitAndLossQuery`, `BalanceSheetQuery`, `GeneralLedgerQuery`, or `EvidenceIndexQuery` contains an `insert`/`update`/`delete`/`upsert`/`save`/`create` call, or a raw `DB::insert`/`update`/`delete`/`statement` call, against any table (`RPT-006`) — a literal source-scan, mirroring `JRN-T032`/`POST-T022`'s established technique. | Architecture (source-scan) |

### 6.7 Integration — golden dataset, real PostgreSQL

All tests in this subsection post one real Expense (M7: Debit Office Supplies, Credit Cash, RM50.00, `financialDate` 2026-08-10) and one real Income (M9: Debit Cash, Credit Consulting Revenue, RM200.00, `financialDate` 2026-08-15) through their own real recording services — never a hand-inserted row — then compute each report and compare against hand-verified expected totals.

| Test ID | Description | Level |
| --- | --- | --- |
| RPT-T024 | The Trial Balance as of 2026-08-31 reconciles exactly: Cash nets Debit RM150.00, `totalDebit() === totalCredit() === 250.00`, `isBalanced() === true` (`RPT-007`, `RPT-009`). | Integration |
| RPT-T025 | The Profit & Loss for August 2026 reconciles exactly: Revenue RM200.00, Expense RM50.00, Net Income RM150.00, `isProfit() === true` (`RPT-009`). | Integration |
| RPT-T026 | The Balance Sheet as of 2026-08-31 reconciles and balances: total Assets RM150.00, Cumulative Net Income RM150.00 Credit, `isBalanced() === true` (`RPT-008`, `RPT-009`). | Integration |
| RPT-T027 | A Draft Journal (created but never Posted) referencing the same Accounts does not appear in, or change the total of, the Trial Balance (`RPT-001`). | Integration |
| RPT-T028 | Two Tenants' Trial Balances never leak into each other, even when one Tenant's postings happen after the other's within the same test (`RPT-002`). | Integration |
| RPT-T029 | An Expense posted with a historical `transactionDate` (and therefore historical `financialDate`), but a real, later `postedAt` wall-clock time, is correctly included in a Trial Balance scoped to the historical period — proving `financialDate`, never `postedAt`, governs report inclusion (`RPT-005`). | Integration |
| RPT-T030 | An M5 Reversal of the golden Expense's Journal nets each affected Account's own `NetBalance` to exactly zero in the Trial Balance, and the Profit & Loss's Net Income to exactly zero — proving no special-casing is needed for a correction chain (§13 "Reversal/Replacement correctness"). | Integration |
| RPT-T031 | The General Ledger drill-down for the Cash Account lists exactly the Expense and Income entries, each resolving to its real Journal ID, correct Direction/amount, and correct linked-Evidence list, with a correctly-computed opening (zero) and closing (RM150.00 Debit) balance (`RPT-010`). | Integration |
| RPT-T032 | The Evidence Index for the period lists both Journals: the Income (posted with an Evidence Reference) reports `hasEvidence() === true` with the correct reference; the Expense (posted with none) reports `hasEvidence() === false` and an empty list — never a fabricated reference (`RPT-011`). | Integration |

### 6.8 Debtors and Aging Report (as of v1.1.0)

Domain-level tests (`AgingBucket`, `AgingReport`) construct their inputs directly, with no persistence; integration-level tests (`AgingReportQuery`) post a real Invoice through `InvoiceIssuingService` and a real Payment/Allocation through `PaymentRecordingService`/`AllocationService`, never a hand-inserted row, mirroring §6.7's own established convention.

| Test ID | Description | Level |
| --- | --- | --- |
| RPT-T033 | An Invoice not yet due, or due exactly on the as-of date (zero days overdue), buckets as `Current` (`RPT-013`). | Unit |
| RPT-T034 | Each bucket boundary (1, 30, 31, 60, 61, 90, 91 days overdue) resolves to its correct, contiguous bucket, with no gap or overlap (`RPT-013`). | Unit |
| RPT-T035 | A fully-paid Invoice (outstanding balance exactly zero as of the as-of date) does not appear in the report (`RPT-012`). | Integration |
| RPT-T036 | An unpaid Invoice not yet due appears, bucketed `Current` (`RPT-012`, `RPT-013`). | Integration |
| RPT-T037 | An Invoice overdue by 15 days appears in the `Overdue1To30` bucket (`RPT-013`). | Integration |
| RPT-T038 | An Invoice overdue by 100 days appears in the `Overdue91Plus` bucket (`RPT-013`). | Integration |
| RPT-T039 | A partially-allocated Invoice shows its remaining outstanding balance (`totalAmount` minus allocations from Payments made on or before the as-of date), not its full `totalAmount` and not zero. | Integration |
| RPT-T040 | A Draft Invoice never appears, regardless of its due date (`RPT-012`). | Integration |
| RPT-T041 | The report's grand total exactly sums every listed line's own outstanding balance, via `Money::add()` (`RPT-012`). | Integration |
| RPT-T042 | `AgingReport::grandTotal()` sums every line correctly at the domain level, with no persistence involved. | Unit |
| RPT-T043 | `AgingReport::totalForBucket()` sums only lines matching the requested bucket, excluding every other bucket's lines. | Unit |
| RPT-T044 | An empty `AgingReport` (zero lines) reports zero for its grand total and every bucket total. | Unit |
| RPT-T045 | A deallocated allocation's Invoice reappears as fully outstanding — proves `AgingReportQuery`'s new `deleted_at IS NULL` filter (P1-3, AETS-009 v1.2.0) preserves this report's exact prior observable behavior after `PaymentAllocation` deletion changed from a hard delete to a soft delete (`RPT-012`). | Integration |
| RPT-T046 | A fully-allocated Invoice's Aging report for a historical as-of date is identical before and after the contributing allocation is later deallocated; the same-day-or-later as-of date correctly reflects the deallocation instead — proves `AgingReportQuery`'s `deleted_at`-vs-as-of-date comparison (P1-4, AETS-009 v1.3.0) (`RPT-014`). | Integration |
| RPT-T047 | A not-yet-allocated Invoice's Aging report for a historical as-of date (fully outstanding) is identical before and after a late allocation is made against an earlier-dated Payment; the current-day as-of date correctly reflects the allocation instead — proves `AgingReportQuery`'s `created_at`-vs-as-of-date comparison, the exact scenario an external audit demonstrated (AETS-009 v1.4.0) (`RPT-014`). | Integration |
| RPT-T048 | Two Tenants sharing the identical MYR amount and dates (Invoice, Payment, Allocation) but distinct Account/Customer/Invoice/Payment IDs never leak into each other's Aging Report or outstanding balance (`RPT-002`, closing the gap AETS-009 §15 named until 2026-09-11). | Integration |
| RPT-T049 | Downloading `/reports/compliance-pack` for a period with real posted activity returns a valid ZIP (real HTTP round trip) containing exactly five named CSV entries; the Trial Balance and Profit & Loss entries are checked directly for the same Account IDs their own `?format=csv` output would contain, and the remaining three entries are checked present and non-empty (`RPT-015`, §19). | HTTP |

## 7. RPT-003 — not independently tested by a runtime test

`RPT-003` ("every report MUST be derivable, in full, from `journals`/`journal_lines`/`accounts` alone, with no independent stored source of truth") is a structural property of the schema and the Query layer's own source, not a behavior a runtime assertion can observe in isolation: there is no "cached balance" code path whose *absence* a test could exercise. It is proven by inspection, mirroring [ATS-010 §7](ATS-010-Audit-Trail-Test-Specification.md#7-aud-007--not-independently-testable)'s identical treatment of `AUD-007`:

- No migration in `database/migrations/` creates a table storing a computed balance, total, or report snapshot — the only tables any Reporting class reads from are `accounts`, `journal_lines`, `journals`, and (for General Ledger and Evidence Index only, per AETS-009 §9–§10's own explicit extension) `audit_events` and `journal_evidence_links`, themselves primary AETS-010 facts, never a cache of a prior report computation.
- `RPT-T023`'s source-scan already proves no Reporting class writes to any table — the only way a stored "source of truth" could exist independently of the ledger is if something, somewhere, wrote one, and no such write path exists.

## 8. Deferred Items

- Cash Flow and Reconciliation Report tests — deferred alongside AETS-009's own deferral (§15), each blocked on a specific, named future module.
- Export (PDF/XLSX) tests — a presentation-layer concern, deferred alongside AETS-009 §15; CSV export is now resolved at the specification level (AETS-009 §18) but, per §2.2, introduces no new business logic this document tests separately.
- Formal reproducibility/property-based tests over a *generated* range of Journal sets — AETS-009 §13 names this as "where practical"; the current suite proves reconciliation against one concrete, hand-verified golden dataset (RPT-T024–RPT-T032) rather than a generated property-based range. Upgrading to a generated range remains a future enhancement, not a gap in current invariant coverage, since every `RPT-NNN` invariant already has at least one concrete passing test.
- Period Management's interaction with reporting — deferred alongside AETS-009 §15, to a future AETS-014 test specification.
- ~~A dedicated cross-tenant isolation test for the Aging Report~~ — resolved as of v1.4.0; see `RPT-T048`.
- **Aging Report historical reproducibility across time** — a design limitation named by AETS-009 §17, not a test gap: no test can prove a guarantee the specification itself does not make (a Payment Allocation's hard delete can change a historical as-of-date result if the report is re-run after the deallocation).

## Changelog

- **1.5.0 (2026-09-16):** Companion update to [AETS-009](../AETS-009-Financial-Reporting.md) v1.5.0 (§19, Compliance Pack export). Adds `RPT-T049`, a real-HTTP proof that `/reports/compliance-pack` returns a valid ZIP with exactly five named CSV entries, checked for fidelity against the Trial Balance/Profit & Loss reports' own already-tested output. Adds a new `RPT-015` traceability row (§5) and a §2.2 scope note distinguishing this from the CSV-export precedent (the ZIP-bundling code path is genuinely new, unlike CSV's zero-new-code-path reshaping). No existing test ID or `RPT-NNN` invariant's prior coverage changed. Classified **MINOR**.
- **1.4.0 (2026-09-11):** Companion update to [AETS-009](../AETS-009-Financial-Reporting.md) v1.4.0. Adds `RPT-T047` (the creation-side boundary an external audit found v1.3.0's own fix still missing) to `RPT-014`'s traceability row, and `RPT-T048` (a dedicated two-Tenant Aging isolation test) to `RPT-002`'s. Closes both remaining §2.2/§8 coverage gaps this document had explicitly named since v1.1.0. No existing test ID or `RPT-NNN` invariant's prior coverage changed. Classified **MINOR**.
- **1.3.0 (2026-09-11):** Companion update to [AETS-009](../AETS-009-Financial-Reporting.md) v1.3.0 (P1-4: full resolution of the Aging Report historical-reproducibility limitation). Adds `RPT-T046`, proving `AgingReportQuery`'s new `deleted_at`-vs-as-of-date comparison: a historical as-of date's result is identical before and after a later deallocation, while a same-day-or-later as-of date correctly reflects it. Adds `RPT-T046` to a new `RPT-014` traceability row (§5). No existing test ID or `RPT-NNN` invariant's prior coverage changed. Classified **MINOR**.
- **1.2.0 (2026-09-11):** Companion update to [AETS-009](../AETS-009-Financial-Reporting.md) v1.2.0 (P1-3 audit remediation: `PaymentAllocation` deletion changed from a hard delete to a soft delete). Adds `RPT-T045` (§6.7's own §6.8 area), a genuinely new test (unlike v1.1.0's catch-up-only additions) proving `AgingReportQuery`'s new `deleted_at IS NULL` filter preserves the report's exact prior observable behavior — a deallocated allocation's Invoice still reappears as fully outstanding, exactly as under the old hard-delete. Adds `RPT-T045` to `RPT-012`'s traceability row (§5). No existing test ID or `RPT-NNN` invariant's prior coverage changed. Classified **MINOR**.
- **1.1.0 (2026-09-08):** Companion update to [AETS-009](../AETS-009-Financial-Reporting.md) v1.1.0, which added §17 (Debtors and Aging Report) and `RPT-012`/`RPT-013`. Adds new §6.8 (twelve test cases, `RPT-T033`–`RPT-T044`), tracing every ID to an already-existing, already-passing test in `AgingBucketTest`, `AgingReportTest`, and `AgingReportQueryIntegrationTest` — no test was newly written for this version; this document catches up to test coverage the M22 implementation already had. Updates §5's traceability matrix and §2.1/§2.2. Explicitly records two coverage gaps this version does **not** close, both already named by AETS-009 §15: no dedicated cross-tenant isolation test for the Aging Report (unlike `RPT-T028` for §6–§10), and no test for CSV export (a presentation-layer reshaping with no new business logic to prove, per AETS-009 §18). No existing `RPT-NNN` invariant's test coverage, and no existing test ID, changed. Classified **MINOR**, mirroring AETS-009's own v1.1.0 classification.
- **1.0.0 (2026-09-07):** Initial version, authored immediately after AETS-009's implementation (M10), fulfilling the exact coverage AETS-009 §13 names as required.
