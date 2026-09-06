# AETS-014: Period Management

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-07
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md), [AETS-002](AETS-002-Accounting-Invariants.md), [AETS-004](AETS-004-Journal-Posting-Model.md), [AETS-005](AETS-005-Chart-of-Accounts.md), [AETS-007](AETS-007-Posting-Command.md), [AETS-009](AETS-009-Financial-Reporting.md); [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0007](../../adr/0007-money-representation-strategy.md)

## 1. Purpose

This document specifies Period closing: the operation that rolls every Revenue and Expense Account's cumulative balance into a designated Retained Earnings (Equity) Account and permanently forbids any further ordinary Posting Command dated on or before the closed date. It resolves the gap [AETS-009](AETS-009-Financial-Reporting.md) §8/§15 named and deferred: the "unclosed books" Cumulative Net Income convention that Balance Sheet used in place of a real closing mechanism.

## 2. Scope

### 2.1 In scope

- Closing the books through a caller-supplied date (`closedThroughDate`), for a single Tenant.
- The double-entry closing-entry construction: zeroing every Revenue/Expense Account, rolling the net effect into Retained Earnings.
- A permanent, ever-advancing closed-period watermark per Tenant, and its enforcement against every future ordinary Posting Command.
- Idempotent, atomic, auditable closing — reusing the existing Posting Command pipeline (M4/AETS-007) unchanged, never a parallel posting mechanism.

### 2.2 Out of scope

- **Reopening a closed Period.** A real capability a future business need may require, but a materially different, separately-designed operation (it would need its own authorization, audit, and reversal-of-a-reversal semantics) — not invented here by extension.
- **Discrete Period entities, fiscal years, or a Period calendar.** This document models closing as a single ever-advancing watermark, not a list of named Periods with their own lifecycle states (Open/Locked/Closed) — SRS does not require the latter for MVP, and inventing it would be speculative generality.
- **Automatic/scheduled closing.** Every closing in this document is an explicit, caller-initiated command.
- **Multi-currency period closing.** Follows [AETS-003](AETS-003-Money-Specification.md)'s own MVP scope (MYR only).
- **Reporting UI/period-selector changes.** How a future Reporting UI lets a user pick "this month" vs "last month" is a presentation concern, not specified here.
- **Reconciling this closing convention against a real accountant's chart-of-accounts numbering practice** (e.g. whether "Retained Earnings" is Account Code 3900) — this document names no canonical Account Code; the Tenant's own Chart of Accounts (AETS-005) decides that.

## 3. Authority

This document implements [ADR-0004](../../adr/0004-financial-integrity-principles.md) and is subordinate to the authority hierarchy in [AETS-000 §3](AETS-000.md#3-authority-hierarchy). It extends, and does not weaken, [AETS-002](AETS-002-Accounting-Invariants.md)'s 14 invariants, [AETS-004](AETS-004-Journal-Posting-Model.md)'s Journal model, and [AETS-007](AETS-007-Posting-Command.md)'s Posting Command contract.

## 4. Definitions

- **Closed-period watermark.** The greatest `closed_through_date` a Tenant has ever closed through. A Tenant with no closure on record has no watermark (every date is open).
- **Closing Journal.** The single Posted Journal a closing operation produces — an ordinary Journal in every respect (AETS-004), distinguished only by its Source reference (`period-closing:{date}`) and by being recorded against a `period_closures` row.
- **Retained Earnings Account.** A caller-designated Equity Account (AETS-005) that receives the net Revenue-minus-Expense effect of the Period being closed. This document names no default; the Tenant's own Chart of Accounts must already contain a suitable Account.
- **Zeroing line.** One Journal Line, on the closing Journal, that brings a single Revenue or Expense Account's net balance for the Period being closed to exactly zero.
- **Plug line.** The one Journal Line, on the closing Journal, on the Retained Earnings Account, that makes the closing Journal balance overall — its Direction and amount are the net Debit/Credit imbalance the zeroing lines alone would otherwise leave (§6.3).

## 5. Closing an ever-advancing watermark, not a list of Periods

A Tenant's entire closing history is a `period_closures` row per closing performed — an append-only log, never updated or deleted (§2.2 excludes reopening). "The current Period" is simply "every date after the greatest `closed_through_date` on record" — there is no separate stored notion of a Period's own identity, start date, or name. This mirrors [AETS-009](AETS-009-Financial-Reporting.md) §5's own "rebuildable, not stored" philosophy: the watermark is a fact derivable by a single query (`MAX(closed_through_date)` for the Tenant), never a cached decision that could drift from the underlying closure history.

## 6. The closing operation

### 6.1 Preconditions

A closing request names: the Tenant, an Idempotency Key, an Actor, the closing Journal's own identity (caller-supplied, mirroring every other Posting Command producer, AETS-007 §11), the date to close through, and the Retained Earnings Account. The Retained Earnings Account MUST have Account Type `Equity` (§8, `PER-002`) — checked before any balance is computed, mirroring [AETS-007 §12](AETS-007-Posting-Command.md)'s own Account-validation-before-posting sequencing.

### 6.2 Rejecting a backward or duplicate close

A request whose `closedThroughDate` is not strictly after the Tenant's current watermark MUST be rejected (`PER-003`) — **unless** a closure already exists for that exact date, in which case the request is allowed to proceed as a *plausible* replay, and the underlying Posting Command pipeline's own Idempotency Key comparison (AETS-007 §6.1) makes the final determination: a matching key and matching computed content replays; a mismatched key or content is a conflicting reuse, rejected exactly as any other Posting Command would be.

### 6.3 Computing the closing entry

Given the Revenue and Expense Accounts' net balances for the range *(previous watermark, closedThroughDate]* — excluding the closing Journal being produced by this same request, if it already exists (the replay case) — the closing Journal is built as:

- For each such Account with a non-zero net balance, one zeroing line of the *opposite* Direction to that Account's own net Direction, for the exact net amount.
- One plug line on the Retained Earnings Account, computed as the Debit/Credit imbalance the zeroing lines alone leave — Credit when the zeroing Debit lines (from Revenue Accounts netting Credit, the normal case) exceed the zeroing Credit lines (from Expense Accounts netting Debit, the normal case) — a profit increasing Equity — and Debit in the reverse case — a loss decreasing Equity. Computed direction-agnostically (never by assuming which side is larger), the identical technique [AETS-009 §7](AETS-009-Financial-Reporting.md#7-profit--loss) already established for Net Income.
- No plug line at all when the zeroing lines already balance exactly (an exact break-even Period).

If every Revenue/Expense Account nets to zero for the range (nothing happened, or a prior closing already absorbed everything), the request is rejected (`PER-005`) — this document never fabricates a Journal Line to satisfy AETS-002's "at least two lines" invariant merely to produce a closure record.

The resulting Journal is submitted through the ordinary Posting Command pipeline (M4/AETS-007) unchanged — the same atomicity, idempotency, Account validation, and Audit Event guarantees every other Posting Command already has, never a parallel mechanism.

### 6.4 Recording the closure

On a newly-posted closing Journal (not a replay), one `period_closures` row is recorded, in the same database transaction as the Posting Command's own — the closing Journal and its closure record commit or roll back together (`PER-006`); there is no way to observe one without the other.

## 7. Enforcement: no ordinary posting into a closed Period

Every Posting Command — Expense, Income, or any future command type — MUST be rejected if its Financial Date falls on or before the Tenant's current closed-period watermark (`PER-004`). This check runs as part of the generic Posting Command pipeline (alongside Account validation, AETS-007 §12), not duplicated per Transactions consumer. The closing Journal itself is never rejected by its own check: the watermark used is whatever it was *before* this same closing's own closure record is written.

## 8. Invariants

| ID | Invariant |
| --- | --- |
| PER-001 | **Retained Earnings must exist and be a real Account.** A closing request naming a nonexistent, cross-Tenant, Inactive, or non-posting-eligible Account is rejected exactly as any other Posting Command's Account reference would be (AETS-007 §12). |
| PER-002 | **Retained Earnings must be an Equity Account.** A closing request naming an Account of any other Account Type is rejected (§6.1). |
| PER-003 | **The watermark only ever advances.** A closing request whose date is not strictly after the current watermark is rejected, unless it is a plausible replay of an already-recorded closure at that exact date (§6.2). |
| PER-004 | **A closed Period accepts no further ordinary posting.** Every Posting Command with a Financial Date on or before the watermark is rejected (§7). |
| PER-005 | **No fabricated closing entry.** A closing request producing zero non-zero Account balances to zero is rejected outright — never a Journal with fewer than two real lines. |
| PER-006 | **Atomic closing.** The closing Journal and its `period_closures` record commit or roll back together (§6.4). |
| PER-007 | **Idempotent closing.** A retried closing request, identified by the same (Tenant, Idempotency Key) pair and equivalent computed content, replays the original closure — it never produces a second Journal or a second `period_closures` row for the same date. |
| PER-008 | **The closing Journal is an ordinary Journal.** It carries no special Journal state, no bypass of Account validation, and is subject to the same reversal/replacement mechanics (M5) as any other Posted Journal, should a correction ever be needed. |
| PER-009 | **Exact Money arithmetic.** The zeroing and plug amounts are computed exclusively through `Money`'s own guarded arithmetic (AETS-003) — never native numeric or floating-point computation. |

## 9. ATS Requirements

A future Period Management ATS (`ATS-014`, numbered to match) MUST include: invariant traceability for every `PER-NNN`; the closing-entry algebra proven for a profit, a loss, an exact break-even, and an Account netting its own non-normal Direction; the watermark-advance and backward/duplicate-rejection rules; the no-posting-into-a-closed-period enforcement, proven against a real ordinary Posting Command; idempotent-replay proof, including the specific "replay must not re-aggregate its own prior closing Journal" correctness property (§6.3); the nothing-to-close rejection; Retained Earnings Account-type validation; and real-PostgreSQL integration tests throughout, consistent with this series' own established precedent.

## 10. Examples (Informative)

- A Tenant posts one Expense (RM50.00) and one Income (RM200.00) in August 2026, then closes through 2026-08-31 naming their own Equity Account "Retained Earnings" (Account Code 3900). The closing Journal: Debit Consulting Revenue RM200.00, Credit Office Supplies RM50.00, Credit Retained Earnings RM150.00. A Trial Balance as of 2026-08-31 now shows Revenue and Expense both at zero, and Retained Earnings at RM150.00 Credit.
- The same Tenant later posts a September Expense dated 2026-09-05 — accepted, since it is after the August watermark. An attempt to backdate an Expense to 2026-08-20 is rejected (`PER-004`): August is closed.
- Retrying the original closing request (same Idempotency Key, same date, same Retained Earnings Account) after a lost response returns the same closure, unchanged — no second Journal, no second `period_closures` row.

## 11. Deferred Items

- **Reopening a closed Period** (§2.2) — a real future capability, deliberately not designed here.
- **Discrete, named Periods and a fiscal-year calendar** (§2.2).
- **Automatic/scheduled closing** (§2.2).
- **Multi-currency closing** (§2.2, follows AETS-003's own MVP scope).
- **This document's own future ATS-014** — §9 states its required coverage; the test specification document is not written here (though the current M13 test suite already satisfies its substance — a formal ATS-014 document remains future work, mirroring [AETS-009](AETS-009-Financial-Reporting.md)'s own honest §15 note before its own ATS-009 was written).

## 12. Change Governance

This document follows [AETS-000](AETS-000.md)'s governance rules in full. A change to any `PER-NNN` invariant, or to any MUST-level requirement in §6–§7, is a MAJOR change. Adding reopening, discrete Periods, or automatic closing is at minimum a MINOR change and MUST NOT weaken or contradict any invariant this version already establishes.

## Changelog

- **1.0.0 (2026-09-07):** Initial creation. Specifies Period closing (watermark-based, not discrete Period entities), the double-entry closing-entry construction and its direction-agnostic plug algebra, closed-period posting enforcement, and 9 `PER-NNN` invariants. Reopening, discrete Periods/fiscal years, and automatic closing explicitly deferred (§2.2), each for a named reason.
