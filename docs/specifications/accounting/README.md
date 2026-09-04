# Accounting Engine Technical Specification (AETS)

This directory contains the Accounting Engine Technical Specification series — the detailed technical design of hore.my's Accounting Core, the deterministic subsystem with exclusive authority over ledger posting ([ADR-0001](../../adr/0001-modular-monolith-architecture.md), [ADR-0004](../../adr/0004-financial-integrity-principles.md)).

Start with [`AETS-000.md`](AETS-000.md). It is the foundation of the series: purpose, scope, authority hierarchy, accounting and AI philosophy, design principles, document governance, versioning, and the planned structure of every later document. AETS-000 is architecture and governance only — it does not itself state accounting invariants or terminology; those are their own documents, below.

## Authority

AETS documents implement accepted ADRs; they do not create or override product or architectural decisions. Conflicts are resolved using the precedence in [`docs/product/reference/README.md`](../../product/reference/README.md) and [`docs/adr/README.md`](../../adr/README.md) — Founder instruction, then `HORE_MY_PROJECT_INSTRUCTIONS.txt`, then `HORE_MY_MASTER_CONTEXT.md`, then the SRS, then the Detailed Project Proposal, then accepted ADRs, then AETS. See AETS-000 §3 for the full statement.

## Lifecycle

Each document carries its own `Status`: `Draft` → `Active` → `Superseded` or `Deprecated`. A superseded document is kept, not deleted, and links to its replacement — the same rule ADRs follow. See AETS-000 §8.3.

## Index

| Document | Status | Version | Summary |
| --- | --- | --- | --- |
| [AETS-000 — Foundation](AETS-000.md) | Active | 1.2.0 | Purpose, scope, authority, philosophy, design principles, governance, versioning |
| [AETS-001 — Accounting Terminology](AETS-001-Accounting-Terminology.md) | Active | 1.2.0 | Canonical accounting vocabulary — 34 defined terms and terminology usage rules |
| [AETS-002 — Accounting Invariants](AETS-002-Accounting-Invariants.md) | Active | 1.1.0 | The 14 non-negotiable financial integrity invariants, extracted from AETS-000 §6 |
| [AETS-003 — Money Specification](AETS-003-Money-Specification.md) | Active | 1.0.1 | Normative Money/Currency/MinorUnits domain model, public contract, persistence mapping, and 15 `MON-NNN` invariants |
| [AETS-004 — Journal & Posting Model](AETS-004-Journal-Posting-Model.md) | Active | 1.0.0 | Normative Journal/Journal Line model, debit/credit semantics, posting lifecycle, atomicity, idempotency, append-only rules, reversal/replacement, and 23 `JRN-NNN` invariants |
| [AETS-005 — Chart of Accounts & Account Taxonomy](AETS-005-Chart-of-Accounts.md) | Active | 1.0.0 | Normative Account model, code/type/normal-balance rules, hierarchy, posting eligibility, system vs. user-created accounts, tenant ownership, lifecycle, Journal Line integration, and 20 `COA-NNN` invariants |

This table, together with [AETS-000 §10](AETS-000.md#10-planned-document-structure), is the single authoritative roadmap for the AETS series — no other document states a competing numbering.

Planned, not-yet-created documents — AETS-006 (Posting Rules), AETS-007 (Accounting Commands), AETS-008 (Bank Reconciliation), AETS-009 (Financial Reporting), AETS-010 (Audit Trail), AETS-011 (AI Accounting Proposal Contract), AETS-012 (Proof of Accuracy), AETS-013 (MyInvois Integration), and AETS-014 (Period Management) — are listed in [AETS-000 §10](AETS-000.md#10-planned-document-structure). They are not reserved or committed to until actually created.

## Test Specifications

`tests/` contains Accounting Test Specifications (ATS) — normative test specifications proving compliance with their corresponding AETS document. Each is numbered to match the AETS document it proves (e.g. `ATS-003` proves `AETS-003`). An ATS specifies what must be tested, at what level, and with what data; it is not implementation code.

| Document | Status | Version | Proves | Summary |
| --- | --- | --- | --- | --- |
| [ATS-003 — Money Test Specification](tests/ATS-003-Money-Test-Specification.md) | Active | 1.3.0 | [AETS-003](AETS-003-Money-Specification.md) | 95 test IDs (`MON-T001`–`MON-T095`) tracing every `MON-NNN` invariant to at least one test; golden MYR cases, property-based generators, persistence and vendor-isolation tests |
| [ATS-004 — Journal & Posting Test Specification](tests/ATS-004-Journal-Posting-Test-Specification.md) | Active | 1.5.0 | [AETS-004](AETS-004-Journal-Posting-Model.md) | 192 test IDs (`JRN-T001`–`JRN-T192`) tracing every `JRN-NNN` invariant to at least one test; balance/reversal property-based generators, golden journal cases, atomicity/idempotency/concurrency and tenant-isolation integration tests, Journal reconstitution (Draft/Posted restoration without a posting side effect), real-PostgreSQL Journal persistence adapter tests (header/line mapping, exact `BIGINT` round-trip, malformed-value rejection), real-PostgreSQL production `journals`/`journal_lines` schema constraint tests (uniqueness, composite-FK same-Tenant Journal/Account integrity, canonical-value `CHECK` constraints, non-negative Money magnitude, migration reversibility), and real-PostgreSQL `JournalRepository` persistence tests (atomic header/line insert with fault-injected rollback, row-lock-based concurrency, Draft->Posted lifecycle persistence, and immutable persisted-Journal/Journal-Line protection) |
| [ATS-005 — Chart of Accounts Test Specification](tests/ATS-005-Chart-of-Accounts-Test-Specification.md) | Active | 1.6.0 | [AETS-005](AETS-005-Chart-of-Accounts.md) | 164 test IDs (`COA-T001`–`COA-T164`) tracing every `COA-NNN` invariant to at least one test; type/normal-balance and hierarchy property-based generators, golden account cases, tenant-isolation, posting-eligibility, Account deactivation-lifecycle, fail-closed incomplete-hierarchy-context, Account reconstitution, Account Origin (System/User-Created) construction and structural System Account protection, real-PostgreSQL Account persistence adapter tests, and real-PostgreSQL production `accounts` schema constraint tests (uniqueness, composite-FK tenant/parent integrity, self-parent and canonical-value `CHECK` constraints, migration reversibility) |

## Creating an AETS document

- Use the next sequential `AETS-NNN` number and a short, descriptive title.
- Copy AETS-000's header shape (`Status`, `Version`, `Effective date`, `Owner`, `Reviewers`, `Related`).
- Cite the ADR(s) and AETS-000/AETS-002 section(s) the document implements.
- Do not introduce anything [AETS-002](AETS-002-Accounting-Invariants.md) (accounting invariants) or AETS-000 §3 (authority hierarchy) prohibits.
- Add the document to the index table above once it exists.

## Creating an ATS document

- Name it `ATS-NNN-Title.md` under `tests/`, matching the AETS document number it proves.
- Copy an existing ATS's header shape (`Status`, `Version`, `Effective date`, `Owner`, `Reviewers`, `Related`).
- Use stable, prefixed test IDs (e.g. `MON-T001`) and trace every invariant of the AETS document it proves to at least one test ID.
- Do not invent behavior the corresponding AETS document does not define — flag ambiguity instead of guessing.
- Add the document to the Test Specifications index table above once it exists.
