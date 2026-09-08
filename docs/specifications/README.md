# hore.my Technical Specifications

This directory contains hore.my's technical specification series. Each series implements accepted [ADRs](../adr/README.md) and shares the same authority precedence recorded in [`docs/product/reference/README.md`](../product/reference/README.md): Founder instruction, then `HORE_MY_PROJECT_INSTRUCTIONS.txt`, then `HORE_MY_MASTER_CONTEXT.md`, then the SRS, then the Detailed Project Proposal, then accepted ADRs, then the specification series themselves.

## Series

| Series | Location | Owns |
| --- | --- | --- |
| **AETS** — Accounting Engine Technical Specification | [`accounting/`](accounting/README.md) | The deterministic Accounting Core: ledger posting, journals, chart of accounts, money, reporting, audit trail, period management, and the accounting-facing contract for AI-produced proposals. |
| **WTS** — Workspace & Task Specification | [`workspace/`](workspace/README.md) | The Workspace and Task module: the Task/Proposal state machine, Human Confirmation semantics, and the Task-lifecycle audit trail — everything [AETS-000 §2.2](accounting/AETS-000.md#22-out-of-scope-for-the-aets-series) explicitly excludes from AETS. |

AETS and WTS are **sibling series**. Neither has authority over the other; both are subordinate to accepted ADRs and every higher-precedence source above them. Where one series references a concept the other owns (for example, WTS referencing a Command or a closed Period), the owning series remains authoritative for that concept's own behavior — the referencing document only describes how its own module calls into it.

A future specification for Document Processing or AI Orchestration, when either module is built, follows the same pattern: its own directory here, its own `README.md` index, its own numbered document series, governed with the same rigor this repository already applies to AETS and WTS.
