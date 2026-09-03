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
| [AETS-000 — Foundation](AETS-000.md) | Active | 1.1.0 | Purpose, scope, authority, philosophy, design principles, governance, versioning |
| [AETS-001 — Accounting Terminology](AETS-001-Accounting-Terminology.md) | Active | 1.1.0 | Canonical accounting vocabulary — 26 defined terms and terminology usage rules |
| [AETS-002 — Accounting Invariants](AETS-002-Accounting-Invariants.md) | Active | 1.1.0 | The 14 non-negotiable financial integrity invariants, extracted from AETS-000 §6 |

Planned, not-yet-created documents (chart of accounts, journal/posting model, money representation design, correction model, period management, bank reconciliation, invoicing and payment allocation, MyInvois submission, reporting, audit trail, AI proposal contract, Proof of Accuracy) are listed from AETS-003 onward in [AETS-000 §10](AETS-000.md#10-planned-document-structure). They are not reserved or committed to until actually created.

## Creating an AETS document

- Use the next sequential `AETS-NNN` number and a short, descriptive title.
- Copy AETS-000's header shape (`Status`, `Version`, `Effective date`, `Owner`, `Reviewers`, `Related`).
- Cite the ADR(s) and AETS-000/AETS-002 section(s) the document implements.
- Do not introduce anything [AETS-002](AETS-002-Accounting-Invariants.md) (accounting invariants) or AETS-000 §3 (authority hierarchy) prohibits.
- Add the document to the index table above once it exists.
