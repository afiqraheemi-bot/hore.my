# ADR-0009: Workspace & Task Module Boundary

- Status: Accepted
- Date: 2026-09-08
- Deciders: Founder / Product Owner; CTO / Technical Partner
- Owners: Workspace and Task; Accounting Core; AI Orchestration; Document Processing
- Related: [`HORE_MY_MASTER_CONTEXT.md`](../product/reference/HORE_MY_MASTER_CONTEXT.md) §5, §8 (Module 3), §19; [`HORE_MY_PROJECT_INSTRUCTIONS.txt`](../product/reference/HORE_MY_PROJECT_INSTRUCTIONS.txt); [ADR-0001](0001-modular-monolith-architecture.md); [ADR-0004](0004-financial-integrity-principles.md); [ADR-0005](0005-ai-provider-abstraction.md); [ADR-0006](0006-transactional-outbox-pattern.md); [ADR-0008](0008-identity-authentication-tenancy-strategy.md); [AETS-000](../specifications/accounting/AETS-000.md) §2.2 (explicitly excludes Workspace/Task from AETS scope); [AETS-007](../specifications/accounting/AETS-007-Posting-Command.md) (Posting Command pipeline — the terminal stage this module must call); HORE.my Product Direction Realignment Discovery Report (2026-09-08, Founder-approved)

## Context

hore.my's authoritative product references describe hore.my as a task-driven accounting workspace: the user supplies evidence or an instruction, the system produces a structured proposal, a human reviews and confirms it, and the engine posts the result. `HORE_MY_MASTER_CONTEXT.md` §4–§5 locks this as "action-first, not conversation-first," with every interaction carrying an objective, status, action, and terminal result. §8 names this capability Module 3, "Workspace dan Task." No module owns it today.

[AETS-000](../specifications/accounting/AETS-000.md) §2.2 explicitly places Workspace/Task outside the AETS series' scope ("non-accounting modules ... these belong to their own specifications"), so no accounting specification governs it, and none should — Task/Proposal lifecycle is a workflow concern, not itself a financial invariant.

As recorded in the Discovery Report (2026-09-08): `apps/api/app/Domain` has no Workspace directory. No Task, Proposal, or state-transition entity, table, or migration exists anywhere in the codebase. Every transaction today (Expense, Income, Transfer, Owner Equity, Invoice, Payment) is recorded through direct HTTP-to-Command translation with no intermediate review step — the frontend's `AppComposer.vue` submits a completed form straight to a REST endpoint, which straight to a validated Accounting Command.

This ADR decides the module's existence, its position inside the existing modular monolith, its relationship to the already-accepted, already-hardened Accounting Core, and the authority boundary AI/Proposal production must respect. It does not decide the concrete Task/Proposal database schema, UI screens, or AI provider selection — the former is the Workspace & Task Specification (WTS) series' job (this ADR authorizes its creation), and the latter remains [ADR-0005](0005-ai-provider-abstraction.md)'s job, unchanged.

## Decision drivers

- Give the "Task → Proposal → Human review → Approved Command" flow a real owning module, per Master Context §8 Module 3.
- Preserve Accounting Core's exclusive posting authority ([ADR-0004](0004-financial-integrity-principles.md)) — a Task/Proposal module must produce Commands through existing contracts, never post directly.
- Preserve AI's proposal-only authority ([ADR-0005](0005-ai-provider-abstraction.md)) — a Proposal is not a Command, and this ADR must make that structurally true, not merely a naming convention.
- Fit inside the existing Laravel modular monolith ([ADR-0001](0001-modular-monolith-architecture.md)) without introducing a new deployable or a new transaction boundary.
- Keep Task-lifecycle audit trail separate from Accounting Core's Journal-scoped audit trail ([AETS-010](../specifications/accounting/AETS-010-Audit-Trail-Evidence-Linkage.md)), so ledger auditability semantics are never diluted by non-financial workflow state.
- Avoid overengineering: Task/Proposal has no financial invariant of its own (unlike Accounting Core) — it needs disciplined governance, not invented accounting-grade complexity.

## Considered options

**Module placement:**
1. A new module, "Workspace and Task," inside the existing `apps/api` Laravel modular monolith, alongside Accounting Core, Transactions, Invoicing, and the other established modules.
2. A separate deployable service (`apps/worker` or a new app) for Task/Proposal orchestration.
3. Embed Task/Proposal state directly inside each existing transaction-type module (e.g., Expense gets its own proposal table) rather than a shared module.

**Relationship to Accounting Core:**
1. The Workspace/Task module depends on Accounting Core's existing public Command contracts (the Expense/Income/Transfer/Invoice/Payment services HTTP controllers already call) as its only path to posting; Accounting Core has zero dependency back on Workspace/Task.
2. Accounting Core is modified to accept "Proposal" as a first-class input type it understands directly.
3. The Task/Proposal module writes directly to accounting tables once a human approves, bypassing existing Command services.

**Specification governance:**
1. A new, independently numbered specification series ("WTS"), governed with the same rigor pattern as AETS (`Status`/`Version`/`Owner`/`Reviewers`/`Related` header, `Draft`→`Active`→`Superseded`/`Deprecated` lifecycle, `MAJOR.MINOR.PATCH` versioning with a changelog) but scoped only to what Task/Proposal actually needs.
2. Fold Task/Proposal specification into AETS despite [AETS-000](../specifications/accounting/AETS-000.md) §2.2's explicit exclusion.
3. No formal specification — design directly in code and pull requests.

## Decision

**Module placement: option 1.** "Workspace and Task" becomes a new backend module at `apps/api/app/Domain/Workspace`, inside the existing modular monolith, exactly as [ADR-0001](0001-modular-monolith-architecture.md) anticipated ("modules ... introduced as their approved MVP capabilities are implemented"). It gets no new deployable, no new database, and no new transaction boundary — it uses the same single PostgreSQL database and the same request-scoped transaction discipline every other module already uses.

**Relationship to Accounting Core: option 1.** The Workspace/Task module depends only on Accounting Core's already-existing, already-tested public Command contracts — the same entry points HTTP controllers call today. Accounting Core is **not** modified to understand "Proposal" as a concept: Proposal approval produces exactly the same Command payload a human typing into `AppComposer` produces today, and Accounting Core cannot tell the difference between an AI-assisted approved Proposal and a purely manual entry, by design. This is the concrete, structural enforcement of [ADR-0004](0004-financial-integrity-principles.md)'s "only a validated accounting command accepted through deterministic Accounting Core controls can post" and [ADR-0005](0005-ai-provider-abstraction.md)'s "an accepted AI proposal remains separate from a posted journal." Accounting Core's authority boundary is not reopened by this ADR — only called into, exactly as it is today.

Options 2 and 3 are rejected. Option 2 would blur exactly the authority boundary ADR-0004/ADR-0005 exist to keep sharp, and would require reopening already-accepted, already-hardened Accounting Core contracts for no financial-integrity benefit. Option 3 would duplicate the same state machine once per transaction type and contradicts Engineering Blueprint §2's "explicit boundaries" principle — Task/Proposal review is a genuinely cross-cutting concern, not owned by any single transaction type.

**Specification governance: option 1.** A new series, **Workspace & Task Specification (WTS)**, lives at `docs/specifications/workspace/`, numbered `WTS-NNN.md` with a `README.md` index — mirroring AETS-000's own governance shape because that governance discipline is what keeps a specification from drifting silently, not because Task/Proposal needs AETS's specific accounting invariants. WTS is explicitly **not** part of the AETS series (consistent with [AETS-000](../specifications/accounting/AETS-000.md) §2.2) and does not use AETS numbering; it is a sibling series operating at the same level (§ below), not subordinate to AETS and without authority to override it. WTS's content is scoped to what Task/Proposal actually requires — the state machine, its persistence and audit shape, the Proposal contract's relationship to Accounting Core Commands, and Human Confirmation semantics — not invented accounting rules.

### Module boundary rules

Applying Engineering Blueprint §4.2 to this specific module:

- Workspace/Task owns `tasks`, `proposals`, and the Task-lifecycle audit/transition table(s). No other module may write to these.
- Workspace/Task depends on (reads/calls only, never reaches into the internals of): Accounting Core's public Command services; Document Processing's evidence references (once that module exists); AI Orchestration's proposal-producing interface (once that module exists, per [ADR-0005](0005-ai-provider-abstraction.md)).
- Accounting Core, Invoicing, Payments, Banking, and Customers have **zero** dependency on Workspace/Task. A Task/Proposal is never a precondition those modules require to function; they remain independently callable exactly as they are today — Manual Entry mode (today's `AppComposer` flow) keeps working completely unmodified.
- A Task-lifecycle transition (Received→Processing→...→Completed) is never written to `audit_events` ([AETS-010](../specifications/accounting/AETS-010-Audit-Trail-Evidence-Linkage.md)'s Journal/Posting-scoped audit table) or to any Command idempotency table — it gets its own audit table, owned by Workspace/Task, so Accounting Core's audit semantics are not diluted by non-financial workflow noise. Once a Proposal is approved and its resulting Command is accepted by Accounting Core, that posting produces its own ordinary `audit_events` row exactly as any Command does today — the two audit trails are correlated (the Task carries the resulting Journal/Command reference) but structurally separate.
- AI Orchestration (when built) may only write to `proposals`, in a `NeedsReview`-or-earlier state. It has no write path to `tasks.state` beyond producing a Proposal, and no write path to any accounting table — unchanged from [ADR-0005](0005-ai-provider-abstraction.md).

## Consequences

### Positive

- Task/Proposal gets a real, owned home instead of accumulating inside Transactions or Accounting Core by convenience.
- Accounting Core's already-tested, already-hardened Command pipeline (including this session's own concurrency fixes) is reused unmodified — this ADR introduces no new financial-integrity surface.
- Manual Entry mode keeps working completely unaffected — it doesn't route through Workspace/Task and never needs to.
- WTS gives future contributors a stable, versioned home for Task/Proposal design decisions without inventing accounting-grade ceremony for a module that has no financial invariant of its own.

### Negative

- A Task's approval-to-posting path has one more hop (Task/Proposal → Command → Accounting Core) than direct manual entry, adding latency and a second place idempotency must be proven correct.
- Two audit trails (Task-lifecycle and Journal-posting) must be correlated by the UI/reporting layer to give a user one coherent story — this correlation is a WTS design responsibility, not solved by this ADR.
- Because Accounting Core never depends on Workspace/Task, it will never "know" a posting was AI-assisted. This is intentional, but any future reporting that wants to distinguish AI-assisted from manual postings must read it off the Task/Proposal side, not the Journal side.

### Risks and mitigations

- **Risk:** A future change has Workspace/Task write directly to `journals`/`accounts` to "save a hop." **Mitigation:** Architecture tests must assert Workspace/Task has no database access to Accounting Core's owned tables beyond its public Command services, mirroring the existing pattern implied by [ADR-0001](0001-modular-monolith-architecture.md)'s "no other module may mutate ledger records directly."
- **Risk:** The Task-lifecycle audit table is designed as an afterthought and can't answer "who approved this and when" under a real incident. **Mitigation:** WTS-001 must specify the transition-audit schema with actor, timestamp, reason, previous state, next state, correlation ID, tenant ID, and evidence reference as a first-class requirement.
- **Risk:** Approval-time idempotency is designed late and reproduces a concurrency bug class already fixed multiple times in this codebase's Accounting Core work. **Mitigation:** WTS-001 must specify the approval action's idempotency/concurrency contract explicitly, and its future test specification must include a genuine two-process concurrency test before implementation is considered complete.

## Validation

- Architecture tests prove Workspace/Task cannot write to `journals`, `journal_lines`, `accounts`, or `audit_events` directly.
- Integration tests prove an approved Proposal's resulting Command is accepted by Accounting Core through the exact same public entry point manual entry uses, with no code-path divergence.
- Concurrency tests prove two concurrent approvals of the same Task/Proposal cannot produce two Commands.
- A cross-tenant Task/Proposal leakage test exists for every new route, mirroring [ADR-0008](0008-identity-authentication-tenancy-strategy.md)'s own established pattern.
- Tenant isolation and audit-trail-correlation tests prove a completed Task's audit story (Task transitions plus the resulting Journal) can be reconstructed by an operator without ambiguity.

## Rollout and rollback

This ADR introduces a new module with no prior data — there is no migration-of-existing-data concern. Implementation proceeds through the WTS specification series (this ADR authorizes its creation) and then the delivery plan recorded in the Discovery Report. Manual Entry mode remains fully functional and unmodified throughout this rollout — the module is purely additive. Reversal of this decision requires no ledger data migration, since Accounting Core never depends on it; it would require only removing the additive module and its routes.

## Compliance

Task/Proposal tables must observe the same tenant isolation, least-privilege, and encryption-in-transit/at-rest posture as every other module (Engineering Blueprint §5.2, Master Context §15). Evidence references a Task carries must reuse Document Processing's eventual retention/classification rules rather than inventing a parallel evidence-handling policy. This ADR authorizes no AI capability, no new external integration, and no capability beyond MVP scope (Master Context §9) — AI Orchestration's actual implementation remains gated by [ADR-0005](0005-ai-provider-abstraction.md) and the future AETS-012 (Proof of Accuracy) exactly as before.
