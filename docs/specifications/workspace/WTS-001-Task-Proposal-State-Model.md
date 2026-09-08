# WTS-001: Task & Proposal State Model

- Status: Active
- Version: 2.0.0
- Effective date: 2026-09-08
- Owner: Workspace and Task (see [`CODEOWNERS`](../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner
- Related: [WTS-000](WTS-000.md); [ADR-0009](../../adr/0009-workspace-task-module-boundary.md); [AETS-007](../accounting/AETS-007-Posting-Command.md) (Posting Command — the terminal integration point); [AETS-010](../accounting/AETS-010-Audit-Trail-Evidence-Linkage.md) (Audit Trail — sibling pattern, not reused directly per [ADR-0009](../../adr/0009-workspace-task-module-boundary.md)); [AETS-014](../accounting/AETS-014-Period-Management.md) (Period Management — closed-period re-check requirement, §6 below)

## 1. Purpose

This document is the normative definition of the Task and Proposal state machine: the states themselves, the transitions allowed between them, the Proposal contract, the transition-audit record shape, and the approval-time idempotency and concurrency contract. It is written for implementers and is the direct technical answer to the state model the Founder's own realignment directive specified.

## 2. Scope

### 2.1 In scope

- The Task entity and its states.
- The Proposal entity and its relationship to a Task.
- The allowed state transitions and the rule that only listed transitions are valid.
- The transition audit record's required fields.
- The approval-time idempotency and concurrency contract.
- The Period-closed re-validation requirement at Command-execution time.

### 2.2 Out of scope

- Concrete UI/screen design.
- AI proposal production mechanics — deferred to the future WTS-004 (must not weaken [WTS-000](WTS-000.md) §5).
- Document/evidence storage — Document Processing's own future specification; this document only requires that a Proposal may carry a nullable evidence reference.
- The concrete field-by-field mapping from a Proposal's payload to each Accounting Command constructor — deferred to the future WTS-002.

## 3. Task states (normative)

| State | Meaning |
| --- | --- |
| `Received` | A Task has been created — evidence or an instruction has been captured — and has not yet been interpreted. |
| `Processing` | Interpretation/extraction is under way (by a human, or by AI Orchestration once it exists). |
| `NeedsInformation` | Interpretation is blocked, awaiting additional input from the user (for example, ambiguous or incomplete evidence). |
| `NeedsReview` | A Proposal exists for this Task and awaits Human Confirmation. |
| `Approved` | A human has confirmed the Proposal. Command construction and submission are about to occur. |
| `Executing` | The resulting Command has been submitted to Accounting Core and its result is awaited within the same request. Because Accounting Core posting is itself synchronous and atomic ([AETS-007](../accounting/AETS-007-Posting-Command.md)), a Task should not normally be observed resting in this state — it exists to make a crash mid-flight detectable and recoverable rather than silently ambiguous. |
| `Completed` | Accounting Core accepted and posted the resulting Command. The Task stores the resulting Journal/Command identifier. |
| `Rejected` | A human explicitly declined the Proposal at `NeedsReview`. Terminal. |
| `Failed` | Accounting Core rejected the Command at `Executing` (for example, validation failure or a closed period). The Task stores the rejection detail verbatim. Terminal. |
| `Cancelled` | The user or the system abandoned the Task before it reached `Approved`. Terminal. |
| `Superseded` | A newer Task/Proposal replaces this one for the same underlying evidence, preventing duplicate processing of the same input. Terminal. |

## 4. Allowed transitions (normative)

| From | To |
| --- | --- |
| `Received` | `Processing` |
| `Processing` | `NeedsInformation` |
| `Processing` | `NeedsReview` |
| `NeedsInformation` | `Processing` |
| `NeedsReview` | `Approved` |
| `NeedsReview` | `Rejected` |
| `NeedsReview` | `Superseded` |
| `Approved` | `Executing` |
| `Executing` | `Completed` |
| `Executing` | `Failed` |
| `Received`, `Processing`, `NeedsInformation`, or `NeedsReview` | `Cancelled` |

No transition outside this table is valid. An implementation must reject an out-of-table transition attempt and leave the Task's state unchanged (TSK-002).

## 5. Proposal contract

A Proposal belongs to exactly one Task. A Task may have more than one Proposal only through `Superseded` chaining — never two simultaneously live Proposals for one Task.

A Proposal carries, at minimum:

- the proposed Command payload, in the same shape the corresponding Accounting Command constructor already accepts — a Proposal never invents a new payload shape (TSK-003);
- a nullable confidence score (human-authored Proposals, as built before AI Orchestration exists, carry no confidence score);
- a producer reference (a human actor reference, or — once AI Orchestration exists — a provider/model/version reference per [WTS-000](WTS-000.md) §5);
- a nullable evidence reference;
- a created timestamp; and
- the owning Tenant ID.

## 6. Invariants (`TSK-NNN`)

- **TSK-001:** Every Task state transition produces an immutable audit record carrying: actor reference, timestamp, reason (nullable for system-automatic transitions, required for a human `Reject`/`Cancel`), previous state, next state, a correlation ID stable per Task, the Tenant ID, and a nullable evidence reference. This table is owned exclusively by Workspace/Task and is never the same table as [AETS-010](../accounting/AETS-010-Audit-Trail-Evidence-Linkage.md)'s `audit_events` ([ADR-0009](../../adr/0009-workspace-task-module-boundary.md) Decision).
- **TSK-002:** Only the transitions listed in §4 are valid. An attempt at any other transition is rejected and produces no state change and no audit record beyond the rejection itself being logged at the application layer.
- **TSK-003:** A Proposal's payload must validate against the same schema/constructor the corresponding manual-entry Command already validates against — there is no separate, weaker validation path for a Proposal-originated Command.
- **TSK-004:** The `Approved`→`Executing` transition carries an idempotency key scoped to `(tenant, Task, Proposal)`, such that two concurrent approval attempts for the same Task produce exactly one Command execution. This must be proven by a genuine two-process concurrency test — not inferred from a UI-level disabled-button convention — before implementation is considered complete.
- **TSK-005:** At `Executing`, before Command submission, the module re-validates that the Task's target Period is not closed ([AETS-014](../accounting/AETS-014-Period-Management.md)) at that instant. A Task approved before a Period closes and executed after must transition to `Failed` with a reason, never post into a closed Period.
- **TSK-006:** An AI-originated Proposal may only be written by an authorized AI Orchestration producer into a `NeedsReview`-or-earlier Task state. Only an authenticated human actor's explicit action may perform `NeedsReview`→`Approved`.
- **TSK-007:** A Task's Tenant ID is immutable from creation and is validated against the acting user's Tenant on every transition; a cross-tenant transition attempt is rejected, never silently no-op'd.
- **TSK-008:** A `Completed` Task is never re-opened. A correction to a completed Task's outcome creates a new Task referencing the original, rather than mutating history — mirroring the ledger's own reversal/replacement discipline ([ADR-0004](../../adr/0004-financial-integrity-principles.md)) at the workflow level.
- **TSK-009:** `Cancelled`, `Rejected`, `Failed`, and `Superseded` are terminal — no transition leaves any of these states.
- **TSK-010 (added v2.0.0):** Every write sequence that spans more than one row across `tasks`, `proposals`, and `task_transitions` is one atomic database transaction — in particular, a Task's whole creation sequence (`Received` through `NeedsReview`, plus its Proposal), and any single transition's compare-and-swap `UPDATE` together with its TSK-001 audit record. A partial write (a Task with no initial transition, a state that disagrees with its own history, a Task with no Proposal) must never be observable, including under a forced mid-sequence failure — proven by fault-injection tests forcing a non-duplicate constraint violation at each such boundary, mirroring [AETS-007](../accounting/AETS-007-Posting-Command.md)'s own established technique.
- **TSK-011 (added v2.0.0):** A Task-creation request reusing an already-used `(Tenant, Idempotency-Key)` pair is a safe replay only if its Proposal payload (Command type, amount, transaction date, both Account references, description) is unchanged from the original; a materially different payload under the same key is rejected, never silently accepted as the stale original. Mirrors [AETS-007](../accounting/AETS-007-Posting-Command.md) §6.1's conflicting-idempotency-reuse rule, applied to Task submission itself rather than only to the resulting Command.
- **TSK-012 (added v2.0.0):** A Task stranded in `Executing` by a crash or lost connection between the `Approved`→`Executing` transition and Command finalization can be recovered: an explicit resume re-attempts Command submission (safe by construction, since TSK-004's idempotency key covers a resume identically to the original attempt) and completes the `Executing`→`Completed`/`Failed` transition. Two concurrent resume attempts against the same stranded Task must never both succeed — proven by a genuine two-process concurrency test. Fulfils this document's own §3 requirement that `Executing` exist so a crash is "detectable and recoverable," not detectable alone.

## 7. Relationship to Accounting Core

Restated from [ADR-0009](../../adr/0009-workspace-task-module-boundary.md) §Decision, made concrete: the `Executing`→`Completed`/`Failed` transition's only interaction with Accounting Core is calling the exact same public Command service a manual entry calls. Workspace/Task holds no direct database access to `journals`, `journal_lines`, or `accounts`. On success, the Task stores the resulting Command/Journal identifier for cross-referencing; on failure, the Task stores the rejection reason Accounting Core returned, verbatim — it never invents its own interpretation of why posting failed.

## 8. Test specification requirement

A future Workspace & Task test specification must trace every `TSK-NNN` invariant in §6 to at least one test, following the pattern already established by this repository's `ATS-NNN` documents (`ATS-003` through `ATS-010`), including a genuine `proc_open`-based two-process concurrency test proving TSK-004.

## Changelog

- **2.0.0 (2026-09-08):** Independent post-implementation QA of the Phase D "Non-AI Workflow Shell" build found four production blockers this document had not made explicit as invariants: `submit()`'s multi-row creation sequence was not atomic; a transition's state update and its TSK-001 audit record were two separate writes, not one; a Task crashing mid-`Executing` had no recovery path despite §3's own claim that state exists to be "recoverable"; and a Task-creation retry under a reused Idempotency Key did not check whether the retried payload actually matched the original. All four are now closed in implementation (transactional writes, `resume()`, payload-conflict detection) and recorded here as TSK-010–TSK-012, per this document's own MAJOR-version rule (a change that alters a contract this document describes). TSK-001–TSK-009 are unchanged.
- **1.0.0 (2026-09-08):** Initial creation, per [ADR-0009](../../adr/0009-workspace-task-module-boundary.md) / [WTS-000](WTS-000.md).
