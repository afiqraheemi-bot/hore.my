# AETS-002: Accounting Invariants

- Status: Active
- Version: 1.1.0
- Effective date: 2026-09-03
- Owner: Accounting Core (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-000](AETS-000.md) §6; [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), [ADR-0007](../../adr/0007-money-representation-strategy.md); [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md)

## 1. Purpose

This document is the authoritative, citable home for hore.my's financial integrity invariants — the non-negotiable constraints every AETS document, and every Accounting Core implementation, must design within. No AETS document, including this one, has authority to relax any invariant listed here; only a superseding ADR to [ADR-0004](../../adr/0004-financial-integrity-principles.md), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md), or [ADR-0007](../../adr/0007-money-representation-strategy.md) can change what is required, and this document would then be updated to match ([AETS-000](AETS-000.md) §8.4).

This content originated in [AETS-000](AETS-000.md) §6 and was extracted here so the Foundation document remains architecture and governance only, while these invariants get a stable, independently versioned, precisely citable home (e.g. "AETS-002 §4") for later AETS documents — chart of accounts, journal and posting model, reconciliation, and so on — to be written against.

## 2. Scope

This document states invariants only. It does not define schemas, algorithms, account structures, or workflows — those belong to the later AETS documents listed in [AETS-000](AETS-000.md) §10, each of which must comply with every invariant below.

## 3. Authority

These invariants are locked by [ADR-0004](../../adr/0004-financial-integrity-principles.md) (financial integrity principles), [ADR-0006](../../adr/0006-transactional-outbox-pattern.md) (transactional outbox), and [ADR-0007](../../adr/0007-money-representation-strategy.md) (money representation). This document restates them for accessibility and cross-reference; where any wording here and the source ADR appear to diverge, the ADR governs, per the authority hierarchy in [AETS-000](AETS-000.md) §3.

## 4. The invariants

1. **Balance.** Every posted journal has at least two lines, and total debit equals total credit exactly.
2. **Atomicity.** The journal, its lines, evidence linkage, audit event, and any required outbox event commit or roll back together, as one database transaction. No network call or queue publication happens inside that transaction ([ADR-0006](../../adr/0006-transactional-outbox-pattern.md)).
3. **Idempotency.** Every material command carries an idempotency key and source fingerprint. Repeating or concurrently retrying a command returns the original economic result and never creates a second posting.
4. **Append-only history.** Posted journals and lines cannot be updated or deleted through the application. Corrections use a linked reversal that mirrors the original effect and, where needed, a separately linked replacement; the original record and the reason for correction stay visible.
5. **Ledger-derived truth.** The authoritative balance comes from the ledger. Projections and reports are derived and must be rebuildable from posted journals — never an independent source of truth.
6. **Controlled periods.** A closed accounting period rejects ordinary posting. Reopening it is explicit, reasoned, verified, and audited.
7. **Bounded allocation.** Payment allocation cannot exceed the unallocated invoice amount and cannot be consumed twice.
8. **No duplicate source events.** The same bank source line cannot be posted twice for the same economic event; reconciliation and import must be duplicate-resistant by construction.
9. **Exact reconciliation.** A completed bank reconciliation has RM0.00 unexplained difference.
10. **Exact money.** The canonical Money representation is integer minor units, stored as PostgreSQL `BIGINT`, per [ADR-0007](../../adr/0007-money-representation-strategy.md)'s Founder-approved amendment. Binary floating point is prohibited anywhere money is stored, compared, aggregated, posted, reconciled, or exported. The Money domain contract itself (Currency, MinorUnits, and related value objects) is formalized separately in [AETS-003](AETS-003-Money-Specification.md) — Money Specification.
11. **Tenant isolation.** Every financial record has immutable tenant ownership; application, query, database, and storage controls must all prevent cross-tenant access.
12. **Complete auditability.** Every material action records actor, tenant, source, time, and applicable policy/model version. Evidence retains its original file, hash, uploader, timestamp, and links.
13. **Deterministic authority.** AI and proposal-producing modules cannot write ledger records; only a validated command accepted through deterministic Accounting Core controls can post (see [AETS-000](AETS-000.md) §5, AI philosophy).
14. **Safe asynchrony.** External or asynchronous work triggered by a posting uses the transactional outbox; delivery is at-least-once, so dispatchers, handlers, and external adapters (including MyInvois submission) must be idempotent.

## 5. Release blocker

Any imbalanced trial balance, unexplained reconciliation difference, duplicate-posting risk, failed tenant isolation, or unproved backup/restore is a **release blocker**, per [`HORE_MY_MASTER_CONTEXT.md`](../../product/reference/HORE_MY_MASTER_CONTEXT.md) §18 and [ADR-0004](../../adr/0004-financial-integrity-principles.md).

## Changelog

- **1.1.0 (2026-09-03):** Updated invariant 10 (Exact money) to reflect [ADR-0007](../../adr/0007-money-representation-strategy.md)'s Founder-approved resolution of the canonical Money representation (integer minor units, PostgreSQL `BIGINT`). No invariant's requirement changed; only the previously-open representation reference was updated to the now-settled fact.
