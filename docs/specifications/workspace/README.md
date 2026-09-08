# Workspace & Task Specification (WTS)

This directory contains the Workspace & Task Specification series — the detailed technical design of hore.my's Workspace and Task module, which owns the "evidence/instruction → Task → Interpretation → Proposal → Human review → Approved Command → Completion" flow ([ADR-0009](../../adr/0009-workspace-task-module-boundary.md)).

Start with [`WTS-000.md`](WTS-000.md). It is the foundation of the series: purpose, scope, authority hierarchy, product and AI-boundary philosophy, design principles, document governance, versioning, and the planned structure of every later document.

## Relationship to AETS

WTS is a **sibling series** to the [Accounting Engine Technical Specification (AETS)](../accounting/README.md), not a subordinate one. Both series implement accepted ADRs and share the same authority precedence (see [WTS-000](WTS-000.md) §3). Neither series may weaken, override, or contradict the other or any higher-precedence source. Where a WTS document references an accounting concept — a Command, a Journal, a closed Period — the corresponding AETS document remains authoritative for that concept's own behavior; WTS only describes how Workspace/Task calls into it.

[AETS-000](../accounting/AETS-000.md) §2.2 explicitly excludes Workspace/Task from the AETS series' scope. This series exists because of that exclusion, not despite it.

## Lifecycle

Each document carries its own `Status`: `Draft` → `Active` → `Superseded` or `Deprecated`. A superseded document is kept, not deleted, and links to its replacement. See [WTS-000](WTS-000.md) §7.3.

## Index

| Document | Status | Version | Summary |
| --- | --- | --- | --- |
| [WTS-000 — Foundation](WTS-000.md) | Active | 1.0.0 | Purpose, scope, authority, product and AI-boundary philosophy, design principles, governance, versioning |
| [WTS-001 — Task & Proposal State Model](WTS-001-Task-Proposal-State-Model.md) | Active | 2.0.0 | Task states, allowed transitions, the Proposal contract, the transition-audit record shape (`TSK-001`), and 12 `TSK-NNN` invariants covering transition validity, Proposal/Command validation parity, approval-time idempotency (`TSK-004`), closed-Period re-validation at execution time (`TSK-005`), the AI/human approval boundary (`TSK-006`), tenant isolation (`TSK-007`), terminal-state discipline (`TSK-008`–`TSK-009`), and — as of v2.0.0, closing four production blockers a post-implementation QA pass found — atomic multi-row writes (`TSK-010`), Task-submission payload-conflict detection (`TSK-011`), and crash recovery for a Task stranded in `Executing` (`TSK-012`) |

This table, together with [WTS-000 §9](WTS-000.md#9-planned-document-structure), is the single authoritative roadmap for the WTS series — no other document states a competing numbering.

Planned, not-yet-created documents — WTS-002 (Proposal-to-Command Translation), WTS-003 (Task Audit Trail & Evidence Correlation), and WTS-004 (AI-Produced Proposal Intake Contract) — are listed in [WTS-000 §9](WTS-000.md#9-planned-document-structure). They are not reserved or committed to until actually created.

## Test Specifications

`tests/` contains Workspace & Task Test Specifications, numbered to match the WTS document they prove and following the pattern established by this repository's `ATS-NNN` documents under [`docs/specifications/accounting/tests/`](../accounting/tests/).

| Document | Status | Version | Proves | Summary |
| --- | --- | --- | --- | --- |
| [WT-001 — Task & Proposal State Model Test Specification](tests/WT-001-Task-Proposal-State-Model-Test-Specification.md) | Active | 1.0.0 | [WTS-001](WTS-001-Task-Proposal-State-Model.md) | 47 test IDs (`TSK-T001`–`TSK-T047`) tracing `TSK-001`–`TSK-012` across unit, real-PostgreSQL integration, HTTP, concurrency, fault-injection and real-browser E2E tests, with partially deferred coverage recorded explicitly for `TSK-006` and `TSK-008` |

## Creating a WTS document

- Use the next sequential `WTS-NNN` number and a short, descriptive title.
- Copy WTS-000's header shape (`Status`, `Version`, `Effective date`, `Owner`, `Reviewers`, `Related`).
- Cite the ADR(s) and WTS-000/WTS-001 section(s) the document implements.
- Do not redefine, weaken, or duplicate any AETS invariant — see [WTS-000](WTS-000.md) §3.
- Add the document to the index table above once it exists.

## Creating a WT document

- Name it `WT-NNN-Title.md` under `tests/`, matching the WTS document number it proves.
- Copy an existing WT document's header shape (`Status`, `Version`, `Effective date`, `Owner`, `Reviewers`, `Related`).
- Use stable `TSK-TNNN` test IDs and trace every invariant in the corresponding WTS document.
- Record genuinely deferred or structurally untestable requirements explicitly; never claim coverage from a test that cannot exercise the behavior.
- Add the document to the Test Specifications index above once it exists.
