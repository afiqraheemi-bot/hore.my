# AETS-009: Financial Reporting

- Status: Active
- Version: 1.1.0
- Effective date: 2026-09-06
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md), [AETS-001](AETS-001-Accounting-Terminology.md), [AETS-002](AETS-002-Accounting-Invariants.md), [AETS-003](AETS-003-Money-Specification.md), [AETS-004](AETS-004-Journal-Posting-Model.md), [AETS-005](AETS-005-Chart-of-Accounts.md), [AETS-010](AETS-010-Audit-Trail-Evidence-Linkage.md); [ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md)

## 1. Purpose

This document is the normative specification for [AETS-000 §10](AETS-000.md#10-planned-document-structure)'s anticipated "AETS-009, Financial Reporting — Report derivation, rebuild-from-ledger guarantees." It defines the minimum set of financial reports SRS §4.9 requires as `Wajib` (mandatory) for MVP that this codebase's existing Accounting Core (M1–M8) and Transactions consumers (M7 Expense, M9 Income) can already support without depending on a module that does not yet exist.

Unlike [AETS-004](AETS-004-Journal-Posting-Model.md), [AETS-005](AETS-005-Chart-of-Accounts.md), and [AETS-007](AETS-007-Posting-Command.md), this document specifies a **read-only projection layer**, not a writer. It has no atomicity, idempotency, or concurrency contract of its own to define, because it never produces a ledger effect — [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 5 ("Ledger-derived truth... Projections and reports are derived and must be rebuildable from posted journals — never an independent source of truth") is this document's entire posture in one sentence. This document is deliberately lighter than the write-side specs it depends on for exactly that reason.

This document uses **MUST**, **MUST NOT**, **SHOULD**, and **MAY** with their normal RFC 2119 meaning, exactly as [AETS-004 §1](AETS-004-Journal-Posting-Model.md#1-purpose) already establishes for this series.

## 2. Scope

### 2.1 In scope

Exactly the SRS §4.9 requirements this codebase's existing modules can support today, with no speculative design of a module that does not exist:

- **Imbangan Duga / Trial Balance** (SRS RPT-004) — §6.
- **Untung Rugi / Profit & Loss** (SRS RPT-001) — §7.
- **Kedudukan Kewangan / Balance Sheet** (SRS RPT-002) — §8.
- **Lejar Am / General Ledger drill-down** (SRS RPT-005) — §9.
- **Indeks Bukti / Evidence Index** (SRS RPT-008) — §10.
- The shared derivation rule every report in scope obeys: Tenant-scoped, Posted-Journal-only, exact Money arithmetic, rebuildable from the ledger alone (§5).
- **As of v1.1.0: Penghutang / Debtors and Aging Report** (SRS RPT-006) — §17, now that its prerequisite Invoice/Accounts-Receivable module (Invoicing, M20; Payments, M21) exists.
- **As of v1.1.0: Eksport CSV** (SRS RPT-009, CSV only) — §18, for every report this document defines.

### 2.2 Out of scope

- **Aliran Tunai / Cash Flow Statement** (SRS RPT-003) — requires an operating/investing/financing activity classification scheme [AETS-005](AETS-005-Chart-of-Accounts.md)'s current five-Account-Type contract does not define. Inventing one speculatively, with no consuming report contract reviewed yet, is exactly the kind of premature design this series' own established convention avoids (mirroring, for example, how [AETS-007 §1](AETS-007-Posting-Command.md#1-purpose) deferred every business-specific Accounting Command until its consumer was actually being built).
- **Laporan Rekonsiliasi / Reconciliation Report** (SRS RPT-007) — requires Bank Reconciliation ([AETS-008](AETS-000.md#10-planned-document-structure), not yet created). Deferred until that module exists.
- **Eksport PDF/XLSX** (SRS RPT-009, PDF and XLSX only) — CSV is now in scope (§18); PDF requires an invoice layout/branding decision that is a Founder-level product call, and XLSX would need a new dependency for marginal gain over CSV at this stage. Both remain deferred to a future UI/export task.
- **Reproducibility as a formally tested guarantee** (SRS RPT-010) — SRS's own priority key marks this `Disyorkan` (recommended), not `Wajib`. This document's own rebuildability requirement (§5) already implies it structurally (a report with no stored, independent state is trivially reproducible from the same posted data), so no separate mechanism is invented merely to formally test what §5 already guarantees by construction.
- **Accounting Period entities, period close/reopen enforcement** — [AETS-014](AETS-000.md#10-planned-document-structure) (Period Management, not yet created) owns whether a period can be closed and whether closed-period posting is rejected. This document uses `financialDate` ([AETS-004 §9.1](AETS-004-Journal-Posting-Model.md#91-financial-date-and-posted-at)) as a plain date-range filter for a report — it does not require, and does not wait for, a formal Accounting Period aggregate to exist first.
- **Report performance/scaling architecture** (read replicas, caching, pre-aggregation) — [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §14's p95-under-two-seconds target is a non-functional requirement this document's derivation rules do not preclude meeting (a direct SQL aggregation over a solopreneur-scale ledger), but the document does not design caching or read-replica infrastructure — that remains an implementation/ops decision, revisited only if a real performance problem is measured.
- **Multi-currency reporting** — [AETS-004 §12](AETS-004-Journal-Posting-Model.md#12-balance-validation) already keeps multi-currency Journals out of MVP scope; every report in this document inherits that same single-Currency assumption without restating it as a new constraint.
- Implementation code, ORM/schema design, and migrations — this is a specification, not code.

## 3. Authority

This document implements [ADR-0004](../../adr/0004-financial-integrity-principles.md)'s Ledger-derived-truth principle, within the terminology of [AETS-001](AETS-001-Accounting-Terminology.md), the invariants of [AETS-002](AETS-002-Accounting-Invariants.md) (specifically invariant 5), the Money contract of [AETS-003](AETS-003-Money-Specification.md), the Journal/Posting model of [AETS-004](AETS-004-Journal-Posting-Model.md), and the Account contract of [AETS-005](AETS-005-Chart-of-Accounts.md). It cannot weaken, override, or contradict any of them ([AETS-000 §3](AETS-000.md#3-authority-hierarchy)) — in particular, it never introduces a write path to `journals`, `journal_lines`, or `accounts`, and never treats a report's own computed output as authoritative in place of the ledger it was derived from.

Dependencies:

- [AETS-002](AETS-002-Accounting-Invariants.md) invariant 5 — the single governing principle this entire document elaborates.
- [AETS-003](AETS-003-Money-Specification.md) — every monetary total this document computes uses Money's own exact arithmetic (`add`, `subtract`, `compareTo`); no report ever uses native numeric arithmetic or a binary float.
- [AETS-004](AETS-004-Journal-Posting-Model.md) §6, §9, §9.1, §12 — the Journal aggregate, its lifecycle states (only `Posted` Journals contribute to any report, §5), and `financialDate` (the date every report filters or as-of's against).
- [AETS-005](AETS-005-Chart-of-Accounts.md) §10–§11 — the five Account Types and their Normal Balance, which every report in this document groups and nets by.
- [AETS-010](AETS-010-Audit-Trail-Evidence-Linkage.md) §8–§9 — the Evidence Reference/Linkage contract the Evidence Index (§10) reads, unchanged.

## 4. Definitions

Every term this document uses that [AETS-001](AETS-001-Accounting-Terminology.md) already defines carries exactly its AETS-001 meaning and is not redefined here.

- **Report** — a read-only projection computed fresh from Posted Journals and Accounts at the moment it is requested. A Report is never itself persisted as authoritative state; a stored cache of one, if ever introduced for performance, MUST remain invalidatable and re-derivable from the ledger without loss (§5).
- **As-of date** — the single Financial Date a point-in-time report (Trial Balance, §6; Balance Sheet, §8) is computed against: every Posted Journal with `financialDate <= as-of date` contributes; nothing later does.
- **Period** — the inclusive Financial Date range `[start, end]` a flow report (Profit & Loss, §7) or a ledger activity listing (General Ledger, §9) is computed against.
- **Net Balance** — an Account's total Debit Money minus total Credit Money, or the reverse, expressed as a single non-negative Money magnitude plus the `JournalDirection` of whichever side is larger — never a signed number, mirroring [AETS-004 §8](AETS-004-Journal-Posting-Model.md#8-debit-and-credit-semantics)'s own "magnitude-with-direction" resolution for a Journal Line. A Net Balance of exactly zero carries the zero Money magnitude and **no** Direction at all (neither side is larger) — this document never derives a Net Balance's Direction from an Account's Normal Balance, since `JournalDirection` and `NormalBalance` are, and remain, unrelated types with no conversion between them ({@see App\Domain\Accounting\Journal\JournalDirection}'s own established contract).

## 5. The shared derivation rule

Every report this document defines (§6–§10) MUST be computed under all of the following, without exception:

1. **Posted-only.** Only Journals whose `state` is `Posted` contribute. A Draft Journal has no ledger effect ([AETS-004 §9](AETS-004-Journal-Posting-Model.md#9-journal-states)) and MUST NOT appear in, or affect the total of, any report.
2. **Tenant-scoped.** Every report is computed for exactly one Tenant. A report MUST NOT aggregate, or leak the existence of, another Tenant's Journal, Journal Line, or Account ([AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 11).
3. **Rebuildable, never independently stored.** A report's numbers MUST be derivable, in full, from `journals`/`journal_lines`/`accounts` alone, at any time, by any two independent computations given the same inputs — producing identical output (this is what "rebuildable" means operationally: no report depends on a mutable running total, a cache with no invalidation path, or any state that could drift from the ledger). This is [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 5, restated as this document's own binding rule, not a new one.
4. **Exact Money arithmetic throughout.** Every sum, difference, and comparison uses [AETS-003](AETS-003-Money-Specification.md)'s own exact operations (`Money::add()`, `Money::subtract()`, `Money::compareTo()`) — never native numeric arithmetic, never a binary float, at any point in any report's computation.
5. **Financial Date, never `postedAt` or `created_at`, is the reporting clock.** Every as-of/period filter in this document filters on [AETS-004 §9.1](AETS-004-Journal-Posting-Model.md#91-financial-date-and-posted-at)'s `financialDate` — the ledger-authoritative accounting date — never on `postedAt` (the system timestamp a Journal was durably posted) or any table's `created_at`. A Journal posted today for an effect dated last month reports against last month, exactly once, in the correct period.
6. **No write.** No report computation MUST ever insert, update, or delete a row in `journals`, `journal_lines`, `accounts`, or any other production table. A report's own presentation layer (§2.2, deferred) MAY cache its output for performance, but that cache is never itself a report's authoritative source.

## 6. Trial Balance (SRS RPT-004)

A Trial Balance, as of a given date, lists **every** Account belonging to the Tenant, together with its cumulative total Debit Money and total Credit Money summed across every Journal Line of every Posted Journal with `financialDate <= as-of date` referencing that Account — cumulative since the Account's own inception, never period-bounded, since a Balance Sheet-eligible Account's balance is a running stock, not a period flow ([AETS-001](AETS-001-Accounting-Terminology.md#balance)).

- An Account with zero contributing Journal Lines still appears, with a zero (directionless, §4) Net Balance — a Trial Balance lists the full Chart of Accounts, not only Accounts that happen to have activity.
- **The golden invariant (`RPT-T001` conceptually, formalized §12): total Debit across every Account line MUST exactly equal total Credit across every Account line.** This is not a Trial-Balance-specific fact invented here — it is the direct, structural consequence of [AETS-004 §7](AETS-004-Journal-Posting-Model.md#7-journal-line)'s own exact-balance requirement (`JRN-007`) holding for every individual Posted Journal; summing exactly-balanced Journals can never produce an imbalanced total. A Trial Balance that fails to balance indicates a defect in this report's own derivation, never a legitimate state of the ledger.
- SRS RPT-001's own acceptance criterion — "Golden report sama trial balance" — is satisfied structurally: the Profit & Loss's (§7) Revenue/Expense totals and the Balance Sheet's (§8) Asset/Liability/Equity totals are each a strict subset-and-regrouping of the exact same Trial Balance computation this section defines, never a separately-derived figure that could disagree with it.

## 7. Profit & Loss / Income Statement (SRS RPT-001)

A Profit & Loss statement, for a given Period `[start, end]`, lists every Revenue-type and Expense-type Account belonging to the Tenant, together with its total Debit Money and total Credit Money summed across every Journal Line of every Posted Journal with `financialDate` inside `[start, end]` (inclusive) referencing that Account — period-bounded, never cumulative since inception, since Revenue and Expense are inherently period flows ([AETS-001](AETS-001-Accounting-Terminology.md), Revenue/Expense terms).

- **Net Income** = (total Revenue Net Balance, Credit-direction magnitude) minus (total Expense Net Balance, Debit-direction magnitude), computed via `Money::compareTo()`/`subtract()` exactly as §4's Net Balance definition requires — never a signed native number. A positive result is a profit for the period; the reverse (Expense exceeding Revenue) is a loss, expressed as a non-negative Money magnitude with an explicit "loss" indicator, never a negative Money value (no such value can exist in this codebase's Money contract, [AETS-003](AETS-003-Money-Specification.md)).
- Every Revenue/Expense Account is grouped by its own [AETS-005](AETS-005-Chart-of-Accounts.md) hierarchy where one exists; this document does not invent a second grouping taxonomy.

## 8. Balance Sheet / Statement of Financial Position (SRS RPT-002)

A Balance Sheet, as of a given date, lists every Asset-type, Liability-type, and Equity-type Account belonging to the Tenant, at the same cumulative-since-inception Net Balance the Trial Balance (§6) already computes for each — a Balance Sheet is a strict subset-and-regrouping of a Trial Balance as of the same date, restricted to these three Account Types, never a separately-derived computation.

**The "unclosed books" equity line — a deliberate, named design decision, not an oversight.** No period-closing mechanism exists in this codebase yet ([AETS-014](AETS-000.md#10-planned-document-structure), Period Management, not yet created) — Revenue and Expense Accounts are never zeroed by a closing entry at a period boundary; they simply keep accumulating from account inception forever. Consequently, for the fundamental accounting identity **Assets = Liabilities + Equity** to hold at any as-of date, a Balance Sheet computed under this document MUST include one additional, computed Equity line — **Cumulative Net Income** — equal to the Profit & Loss (§7) computation for the Period `[account inception, as-of date]` (i.e. every Posted Journal ever, not just the most recent period). This is the standard "unclosed books" convention any double-entry system without period-closing must use; it is not a new accounting rule invented here, and it MUST NOT be confused with, or replace, a future Period Management module's own closing-entry mechanism (§2.2) — once that exists, this computed line is superseded by whatever real, posted closing entries that module produces, and this document (or a successor revision) will need to be revisited at that time.

- **The golden invariant (`RPT-T00X` conceptually, formalized §12): total Assets MUST exactly equal total Liabilities plus total Equity (including the Cumulative Net Income line above).** Any other outcome indicates a defect in this report's own derivation — it can never legitimately arise from a ledger every one of whose Journals independently balances (§6's own reasoning applies transitively).

## 9. General Ledger drill-down (SRS RPT-005)

For a single Account and a given Period `[start, end]`, the General Ledger listing enumerates, in `financialDate` order (ties broken by `postedAt`, never the reverse), every Journal Line of every Posted Journal referencing that Account whose `financialDate` falls inside the Period — each entry carrying its Journal's stable identifier, `financialDate`, `postedAt`, the Line's own Money amount and Direction, and the Journal's Source reference ([AETS-004 §19](AETS-004-Journal-Posting-Model.md#19-actor--source--evidence-traceability)) and any linked Evidence References ([AETS-010 §8–§9](AETS-010-Audit-Trail-Evidence-Linkage.md#8-evidence-reference--minimal-contract)) — so a user can trace any number on any other report in this document back to the exact Journal, and from there to its original evidence, satisfying SRS RPT-005's own acceptance criterion ("Setiap baris dijejak" — every line is traced).

- An opening balance (the Account's Net Balance as of the day immediately before the Period's `start`, per §6's own cumulative computation) and a running balance after each listed entry MAY be included as a convenience; neither is itself authoritative — both remain fully re-derivable from the same underlying Journal Lines (§5).
- This report never surfaces a Draft Journal or a Journal belonging to a different Tenant (§5).

## 10. Evidence Index (SRS RPT-008)

For a given Period `[start, end]`, the Evidence Index lists every Posted Journal belonging to the Tenant with `financialDate` inside the Period, together with: its Source reference, whether it carries at least one linked Evidence Reference ([AETS-010 §9](AETS-010-Audit-Trail-Evidence-Linkage.md#9-evidence-linkage)), and, where present, each linked Evidence Reference's own opaque value — satisfying SRS RPT-008's own acceptance criterion ("Bukti hilang dikenal pasti" — missing evidence is identifiable).

- A Journal with zero linked Evidence References is not itself a defect (per [AETS-010 §9](AETS-010-Audit-Trail-Evidence-Linkage.md#9-evidence-linkage), a pure Reversal or a purely-manual command legitimately carries none) — this report surfaces the fact plainly; it does not classify or flag which absences are "expected" versus "concerning," since that judgment is a Transactions-domain or user-facing concern this document does not decide.
- This report reads [AETS-010](AETS-010-Audit-Trail-Evidence-Linkage.md)'s existing Evidence Reference/Linkage contract entirely unchanged — it does not add a field to it, and does not require Evidence's own still-deferred full schema (AETS-010 §2.2) to exist.

## 11. Tenant isolation and security

Every report defined in this document MUST be requested with, and computed for, exactly one Tenant — there is no "all Tenants" report, and no report parameter can widen its own scope beyond the Tenant it was requested for. Application, query, and database controls MUST prevent cross-tenant access to any report's underlying data, consistent with [AETS-004 §18](AETS-004-Journal-Posting-Model.md#18-tenant-isolation) and [AETS-002 §4](AETS-002-Accounting-Invariants.md#4-the-invariants) invariant 11 — the identical requirement every write-side document in this series already states, restated here for the read side.

## 12. Invariants

Each invariant below is Financial-Reporting-specific, additional to [AETS-002](AETS-002-Accounting-Invariants.md)'s 14 invariants and to this series' existing `JRN-NNN`/`COA-NNN`/`AUD-NNN` invariants, which each already state the underlying ledger fact a report merely reads.

| ID | Invariant |
| --- | --- |
| RPT-001 | **Posted-only.** No report MUST include, or be affected by, a Draft Journal (§5). |
| RPT-002 | **Tenant-scoped.** No report MUST aggregate or reveal another Tenant's data (§5, §11). |
| RPT-003 | **Rebuildable.** Every report MUST be derivable, in full, from `journals`/`journal_lines`/`accounts` alone, with no independent stored source of truth (§5). |
| RPT-004 | **Exact Money arithmetic.** No report MUST use native numeric arithmetic or a binary float at any point in its computation (§5). |
| RPT-005 | **Financial Date is the reporting clock.** No report MUST filter or group by `postedAt` or `created_at` in place of `financialDate` (§5). |
| RPT-006 | **No write.** No report computation MUST perform an insert, update, or delete against `journals`, `journal_lines`, or `accounts` (§5). |
| RPT-007 | **Trial Balance exact balance.** A Trial Balance's total Debit MUST exactly equal its total Credit, for any Tenant, at any as-of date (§6). |
| RPT-008 | **Balance Sheet exact balance.** A Balance Sheet's total Assets MUST exactly equal its total Liabilities plus total Equity (including the Cumulative Net Income line, §8), for any Tenant, at any as-of date. |
| RPT-009 | **Golden-report consistency.** A Profit & Loss's and a Balance Sheet's own totals MUST each be a strict subset-and-regrouping of the same Trial Balance computation for the same Tenant and date/Period — never a separately-derived figure that could disagree with it (§6, §7, §8). |
| RPT-010 | **General Ledger traceability.** Every entry the General Ledger drill-down lists MUST resolve back to a real, Posted Journal Line belonging to the Tenant and Account requested (§9). |
| RPT-011 | **No fabricated Evidence status.** The Evidence Index MUST report a Journal's true linked-Evidence state exactly as [AETS-010](AETS-010-Audit-Trail-Evidence-Linkage.md) records it — never inferring, assuming, or fabricating an Evidence Reference that was not actually linked (§10). |
| RPT-012 | **Aging completeness.** The Debtors/Aging report MUST list every Issued Invoice belonging to the Tenant with a non-zero outstanding balance as of the requested date exactly once, in exactly one bucket; a fully-paid, Draft, or not-yet-issued (as of that date) Invoice MUST NOT appear (§17). |
| RPT-013 | **Aging bucket determinism.** An Invoice's aging bucket MUST be computed solely from whole days overdue (as-of date minus due date), never from an Account's Normal Balance, a Journal Line's Direction, or any other unrelated signal; the same (Invoice, as-of date) pair MUST always resolve to the same bucket (§17). |

## 13. ATS Requirements

This section states what a future Financial Reporting Test Specification (`ATS-009`, numbered to match this document) MUST prove; it does not write that test specification.

A Financial Reporting ATS MUST include:

- **Invariant traceability** — every `RPT-NNN` invariant (§12) traced to at least one test, mirroring this series' own established traceability-matrix pattern.
- **Golden dataset reconciliation** — a representative range of posted Journals (including Expense, M7, and Income, M9, postings) whose Trial Balance, Profit & Loss, and Balance Sheet are computed and checked against hand-verified expected totals, proving `RPT-007`–`RPT-009` — property-based where practical (a generated range of balanced Journal sets, each proven to still balance in the resulting Trial Balance and Balance Sheet), rather than fault-injection/concurrency-style tests, since this document has no write path to fault-inject against.
- **Draft-exclusion proof** — a Draft Journal present in the database does not appear in, or affect the total of, any report (`RPT-001`).
- **Tenant isolation proof** — a report requested for Tenant A never reflects Tenant B's Journals or Accounts, even when both share overlapping Account identifiers or dates (`RPT-002`).
- **Financial-Date-vs-postedAt proof** — a Journal posted on one date for an effect dated on a materially different date reports against its `financialDate`, never its `postedAt` (`RPT-005`), proven directly against real PostgreSQL.
- **Reversal/Replacement correctness** — a Reversed or Replaced Journal chain (M5) still nets to the structurally correct Trial Balance/Balance Sheet totals (the reversal's neutralizing effect, plus the replacement's corrected effect, sum exactly as expected) — proving this document's reports require no special-casing for corrections, only summing every Posted Journal that exists.
- **General Ledger traceability proof** — every listed entry resolves back to a real Journal Line, in the correct order, with the correct Source/Evidence references attached (`RPT-010`).
- **Evidence Index accuracy proof** — a Journal with linked Evidence appears with it; a Journal with none appears with an explicit absence, never a fabricated reference (`RPT-011`).
- **Real PostgreSQL integration tests** — every claim above proven against a real PostgreSQL instance, never SQLite, consistent with this series' own established precedent.
- **No-write architecture proof** — a static/architectural test proving no report class's source contains an `insert`/`update`/`delete` call against any production table (`RPT-006`), mirroring `JRN-T032`/`POST-T022`'s existing no-network/no-side-effect technique.
- **As of v1.1.0: Aging completeness and bucket determinism proof** — a fully-paid, Draft, and not-yet-issued Invoice each proven absent from the Aging report; an Invoice at each bucket boundary (0, 1, 30, 31, 60, 61, 90, 91+ days overdue) proven to land in the correct bucket; a partially-allocated Invoice's outstanding balance proven to reflect only allocations from Payments made on or before the as-of date (`RPT-012`, `RPT-013`, §17).

## 14. Examples (Informative)

These examples are illustrative only and are not normative.

- A Tenant posts one Expense (Debit `ACCOUNT-OFFICE-SUPPLIES` RM50.00, Credit `ACCOUNT-CASH` RM50.00) and one Income (Debit `ACCOUNT-CASH` RM200.00, Credit `ACCOUNT-CONSULTING-REVENUE` RM200.00) within the same month. That month's Profit & Loss shows Revenue RM200.00, Expense RM50.00, Net Income RM150.00 (profit). A Balance Sheet as of month-end shows `ACCOUNT-CASH` at a net Debit Net Balance of RM150.00, and an Equity side carrying a Cumulative Net Income line of RM150.00 — Assets (RM150.00) exactly equal Liabilities (RM0.00) plus Equity (RM150.00).
- The Expense above is later reversed and replaced with a corrected RM80.00 amount (M5). The Trial Balance as of any date after the replacement reflects the net combined effect of the original, its reversal, and the replacement — RM80.00 of expense — with no special-case logic in this document's own reports; the correction chain is simply more Posted Journals to sum.

## 15. Deferred Items

- **Cash Flow Statement** (SRS RPT-003) — deferred pending an operating/investing/financing Account classification scheme (§2.2).
- ~~**Debtors and Aging report**~~ — resolved; see §17. Its prerequisite Invoice/Accounts-Receivable module (Invoicing, M20; Payments, M21) now exists.
- **Reconciliation Report** (SRS RPT-007) — deferred pending Bank Reconciliation, AETS-008 (§2.2).
- **Export to PDF/XLSX** (SRS RPT-009, PDF and XLSX only) — a presentation-layer concern, deferred to a future UI/export task (§2.2). CSV is resolved; see §18.
- **Formal reproducibility testing** (SRS RPT-010, `Disyorkan`) — implied structurally by §5's rebuildability rule; not separately tested by name (§2.2).
- **Aging Report historical reproducibility across time** — named as a deliberate, currently-unresolved limitation, not silently accepted (§17): a Payment Allocation's hard delete ({@see AllocationService::deallocate()}) leaves no history, so a historical as-of-date Aging Report can change if re-run after a contributing allocation is later deallocated. Closing this would require an append-only allocation/deallocation log, not designed here.
- **Aging Report cross-tenant isolation test** — `AgingReportQuery` scopes every query by `tenant_id` (§17), consistent with §11, but no dedicated test proves two Tenants' Aging Reports never leak into each other, unlike `RPT-002`'s dedicated proof for §6–§10's reports (`RPT-T028`). Tracked as an open test-coverage gap, not a known defect.
- ~~**Period Management integration**~~ — resolved; see [AETS-014](AETS-014-Period-Management.md), which specifies Period closing. No change to this document's own Balance Sheet computation was needed: a closing Journal's zeroing lines are ordinary Posted Journal Lines, so `ProfitAndLossQuery::forPeriod([inception, asOfDate])`'s existing "since inception" aggregation automatically nets a closed range's original activity against that same range's closing entries to zero, leaving the "unclosed books" Cumulative Net Income line correctly reflecting only activity since the last closing — verified directly (`PeriodClosingServiceIntegrationTest::test_the_unclosed_books_convention_correctly_shows_only_post_closing_activity`).
- **Report caching/performance infrastructure** — read replicas, pre-aggregation, or caching, should a real performance problem be measured against the p95 target ([`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §14) — not designed speculatively here (§2.2).
- **This document's own future ATS-009 update for §17/§18** — §13's new bullet states the required coverage; [ATS-009](tests/ATS-009-Financial-Reporting-Test-Specification.md) is updated alongside this version (see its own changelog) to trace `RPT-012`/`RPT-013` to the already-existing Aging test suite, but does not yet add the dedicated cross-tenant Aging test the bullet above names as missing.

## 16. Change Governance

This document follows [AETS-000](AETS-000.md)'s governance rules in full — it does not restate them. Lifecycle, review requirements, and versioning (`MAJOR.MINOR.PATCH` with a changelog note for any `Active`-document change, [AETS-000 §9.1](AETS-000.md#91-per-document-version)) all apply unchanged.

A change to any `RPT-NNN` invariant, or to any MUST-level requirement in §5–§11, is a MAJOR change under that rule. Adding a new report definition for a capability already `Wajib` in SRS but currently deferred here (§2.2) — once its prerequisite module exists — is at minimum a MINOR change; it MUST NOT weaken or contradict any invariant this version already establishes.

## 17. Debtors and Aging Report (SRS RPT-006)

For a given as-of date, the Debtors/Aging report lists every Issued Invoice belonging to the Tenant with a non-zero outstanding balance as of that date, each assigned to exactly one aging bucket based on how overdue its due date is relative to the as-of date — resolving the deferral §2.2/§15 previously stated pending "an Invoice/Accounts-Receivable module." That module (Invoicing, M20; Payments, M21) now exists, and this section specifies the report exactly as already implemented, per [AETS-000 §9.1](AETS-000.md#91-per-document-version)'s rule that resolving a named deferred item is itself at minimum a MINOR change.

**Outstanding balance**, for an Invoice as of a given date, is its `totalAmount` minus the sum of every Payment Allocation against it where the allocating Payment's own `paymentDate <= as-of date` — never the full allocation total regardless of date, since a Payment made after the as-of date cannot retroactively have settled a debt as of that date.

**Aging buckets** are computed from whole days overdue (`as-of date` minus `dueDate`), never from an Account's Normal Balance, a Journal Line's Direction, or any other unrelated signal (`RPT-013`):

| Days overdue | Bucket |
| --- | --- |
| ≤ 0 (not yet due, or due exactly on the as-of date) | Current |
| 1–30 | Overdue 1 to 30 days |
| 31–60 | Overdue 31 to 60 days |
| 61–90 | Overdue 61 to 90 days |
| 91+ | Overdue 91+ days |

- A Draft Invoice never appears — only `Issued` Invoices are eligible (`RPT-012`).
- An Invoice issued after the as-of date never appears, regardless of status.
- A fully-paid Invoice (outstanding balance exactly zero as of the as-of date) contributes no line (`RPT-012`).
- The report's grand total, and each bucket's own subtotal, are the exact sum (via `Money::add()`, never native arithmetic) of every listed line's own outstanding balance (§5 rule 4, applied here unchanged).
- Tenant isolation (§11) applies unchanged: a report requested for one Tenant never reflects another Tenant's Invoices, Payments, or Allocations — though see §15's own note on this specifically not yet having a dedicated test, unlike `RPT-002`'s proof for §6–§10.

**This report's relationship to §5's shared derivation rule.** Rules 1 (Posted-only, read as "only an Issued Invoice's own posted effects count" here), 2 (Tenant-scoped), 4 (exact Money arithmetic), and 6 (no write) apply to this report exactly as to every other (§6–§10). This report additionally reads `invoices` and `payment_allocations` directly — tables §5 did not originally anticipate a report reading from (§6–§10 read only `journals`/`journal_lines`/`accounts`, plus, for §9–§10, `audit_events`/`journal_evidence_links`) — because an outstanding balance is a receivables-subledger fact, not a Journal Line sum: [AETS-007 §26.7](AETS-007-Posting-Command.md#267-payment-allocation--not-an-accounting-command-m21) already establishes that Payment Allocation posts no Journal at all, so no Journal Line exists for this report to sum in the first place. Rule 5 (Financial Date as the reporting clock) is honored in spirit — this report filters on Invoice's own `issue_date` and Payment's own `payment_date`, the same accounting-date fields [AETS-007 §26.5–§26.6](AETS-007-Posting-Command.md#265-invoice-issuing-command-m20) already established as each command's own Financial Date — but not by a Journal's `financialDate` field directly, for the same reason.

**A named limitation, not a defect: historical reproducibility depends on allocation history being intact.** Rule 3 (rebuildable) holds exactly as stated — two independent computations of this report against the *same* database state, for the same Tenant and as-of date, always produce identical output. What this report does **not** guarantee is that a *past* as-of date's result stays identical *across time* if a Payment Allocation contributing to it is later deallocated: `PaymentAllocation` deletion ({@see App\Domain\Payments\AllocationService::deallocate()}) is a hard delete with no retained history, so an Aging Report computed for a historical as-of date, re-run after such a deallocation, reflects the allocation's absence even though it existed as of that historical date. This is recorded as a genuine, currently open item (§15), not silently accepted — a future revision introducing allocation history (an append-only allocation/deallocation log) would close it; this document does not design that mechanism now.

- Implemented by [`AgingReportQuery`](../../../apps/api/app/Infrastructure/Invoicing/Reporting/AgingReportQuery.php), returning an [`AgingReport`](../../../apps/api/app/Domain/Invoicing/Reporting/AgingReport.php) of [`AgingReportLine`](../../../apps/api/app/Domain/Invoicing/Reporting/AgingReportLine.php)s, each assigned an [`AgingBucket`](../../../apps/api/app/Domain/Invoicing/Reporting/AgingBucket.php).

## 18. Export presentation — CSV (SRS RPT-009, CSV portion only)

Resolving, for CSV specifically, the deferral §2.2/§15 previously stated in full for "PDF/XLSX/CSV": every report this document defines (§6–§10, §17) MAY be requested in CSV form instead of JSON, via a `format=csv` request parameter, unchanged in every other respect — the same Query-layer computation (§5) runs; only the HTTP-layer presentation differs.

- **No new business logic.** CSV rendering reshapes an already-computed report's own existing fields into rows and a header line; it introduces no new report field, no new computation, and no new validation rule. §5's entire derivation rule (Posted-only, Tenant-scoped, rebuildable, exact Money arithmetic, Financial-Date-governed, no write) governs the underlying computation exactly as for the JSON form — CSV is a pure reshaping of the same output.
- **Money values render as exact decimal strings** (`Money::toDecimalString()`), never a native float — the same representation the JSON form already uses.
- PDF and XLSX remain deferred (§15): PDF requires an invoice layout/branding decision this document does not make (a Founder-level product call, not an engineering default), and XLSX would need a new dependency for marginal gain over CSV at this stage.
- Implemented by [`CsvResponseBuilder`](../../../apps/api/app/Http/Support/CsvResponseBuilder.php), invoked from [`ReportingController`](../../../apps/api/app/Http/Controllers/Api/ReportingController.php) for every report this document defines.

## Changelog

- **1.1.0 (2026-09-08):** Resolved a governance gap an external audit surfaced: M22 (Aging Report) and M23 (CSV export) had both shipped, with real Query/Controller code and real tests, without this document ever being updated to specify either — §2.2/§15 still described both as fully deferred, pending prerequisites that had in fact already been built. Adds new **§17, Debtors and Aging Report**, specifying the outstanding-balance computation, the five aging buckets, and this report's relationship to §5's shared derivation rule (including one explicitly named, currently-unresolved limitation: historical reproducibility across time is not guaranteed once a contributing Payment Allocation is later deallocated, since deallocation is a hard delete with no retained history). Adds new **§18, Export presentation — CSV**, resolving the CSV portion only of SRS RPT-009 for every report this document defines; PDF and XLSX remain deferred. Adds `RPT-012` (Aging completeness) and `RPT-013` (Aging bucket determinism) to §12, and a corresponding ATS-009 coverage bullet to §13. Updates §2.1/§2.2/§15 to move Debtors/Aging and CSV export from deferred to specified, while explicitly recording two residual open items §15 now names for the first time: the historical-reproducibility limitation above, and the absence of a dedicated cross-tenant isolation test for the Aging Report specifically (unlike `RPT-002`'s existing proof for §6–§10). No existing report definition, `RPT-NNN` invariant, or previously specified MUST-level requirement in §5–§11 changed — classified **MINOR** per [AETS-000 §9.1](AETS-000.md#91-per-document-version) and this document's own §16 rule ("adding a new report definition... once its prerequisite module exists — is at minimum a MINOR change").
- **1.0.2 (2026-09-07):** Editorial: §15's Period Management deferral is resolved — [AETS-014](AETS-014-Period-Management.md) now exists, and this document's own Balance Sheet computation required no code change (verified directly). No invariant or previously specified behavior changed.
- **1.0.1 (2026-09-07):** Editorial: §15's forward-reference to this document's own future ATS-009 now links to that document, which has since been written. No invariant, MUST-level requirement, or previously specified behavior changed.
- **1.0.0 (2026-09-06):** Initial creation. Specifies Trial Balance (RPT-004), Profit & Loss (RPT-001), Balance Sheet (RPT-002), General Ledger drill-down (RPT-005), and Evidence Index (RPT-008) — the five SRS §4.9 `Wajib` reports this codebase's existing Accounting Core (M1–M8) and Transactions consumers (M7, M9) can support without depending on a not-yet-built module. Cash Flow (RPT-003), Debtors/Aging (RPT-006), and Reconciliation Report (RPT-007) are explicitly deferred by name (§2.2, §15), each blocked on a specific, named future module — not silently dropped. Introduces `RPT-001`–`RPT-011`. Documents the "unclosed books" Cumulative Net Income convention (§8) as a deliberate, named design decision pending AETS-014 (Period Management). Reviewed and marked `Active` per the Founder's M10 Architecture Review approval (GO for this exact scope; RPT-003/006/007 explicitly NO-GO pending their prerequisite modules).
