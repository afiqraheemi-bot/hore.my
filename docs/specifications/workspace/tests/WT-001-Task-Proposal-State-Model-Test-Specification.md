# WT-001: Task & Proposal State Model Test Specification

- Status: Active
- Version: 1.3.0
- Effective date: 2026-09-16
- Owner: Workspace and Task (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner
- Related: [WTS-000](../WTS-000.md), [WTS-001](../WTS-001-Task-Proposal-State-Model.md); [ADR-0009](../../../adr/0009-workspace-task-module-boundary.md); [AETS-007](../../accounting/AETS-007-Posting-Command.md), [AETS-010](../../accounting/AETS-010-Audit-Trail-Evidence-Linkage.md), [AETS-014](../../accounting/AETS-014-Period-Management.md)

## 1. Purpose

This document is the normative Workspace & Task test specification proving compliance with [WTS-001: Task & Proposal State Model](../WTS-001-Task-Proposal-State-Model.md) — the counterpart WTS-001 §8 itself calls for, mirroring the `ATS-NNN` pattern already established for the AETS series. Every test defined here is identified by a stable ID (`TSK-T001`–`TSK-T082`) and traced to the `TSK-NNN` invariant(s) it proves (§5).

Like [ATS-010](../../accounting/tests/ATS-010-Audit-Trail-Test-Specification.md), this document was authored *after* WTS-001's implementation, not before it — every test ID below traces to a concrete, already-passing test (Unit, Integration, HTTP, or E2E), not a future target. The traceability matrix in §5 can be verified directly against the current test suite.

## 2. Scope

### 2.1 In scope

- A complete traceability matrix from `TSK-001`–`TSK-014` to test IDs.
- Concrete test cases across five levels: pure state-machine unit tests (`Tests\Unit\Domain\Workspace\TaskTest`), real-PostgreSQL service-level integration tests including genuine two-process concurrency and fault-injection (`Tests\Feature\Domain\Workspace\TaskServiceIntegrationTest`), isolated real-PostgreSQL migration tests (`Tests\Feature\Infrastructure\Workspace\WorkspaceTablesMigrationTest`), real-HTTP tests (`Tests\Feature\Http\Api\IdentityAndAccountingApiTest`), and real-browser end-to-end tests (`apps/web/tests/e2e/*.spec.ts`).
- As of v1.3.0: the `NeedsInformation`/`Superseded` application flows §2.2 previously excluded for having no code — TSK-013's deferred-Account-decision path (`saveForLaterCompletion()`, `provideInformation()`) and TSK-014's edit/supersede correction path (`supersedeAndSubmitCorrection()`) — are now real, orchestrated, and tested at every level below.

### 2.2 Out of scope

- Anything WTS-001 itself does not define: Proposal editing *other than* via TSK-014's supersede/correction mechanism, AI-produced Proposal intake (deferred to the future WTS-004) — no test below claims coverage of either.
- A correction mechanism for a `Completed` Task (TSK-008's own second clause) — still unbuilt; see §7's updated discussion of the distinction from TSK-014.
- Accounting Core's own invariants (Money, Journal, Posting Command, Period Management) — already fully specified by the `ATS-NNN` series under `docs/specifications/accounting/tests/`. Tests here that exercise Accounting Core (e.g. `TSK-T020`, `TSK-T042`) do so only to prove the Workspace/Task boundary, never to re-prove Accounting Core's own behavior.

## 3. Authority

This document is subordinate to [WTS-001](../WTS-001-Task-Proposal-State-Model.md) and the authority hierarchy in [WTS-000 §3](../WTS-000.md#3-authority-hierarchy). No contradiction between this document and WTS-001 was found while drafting it.

## 4. Test Strategy

- **Prove the invariant, not the implementation.** Every test traces to a `TSK-NNN` invariant.
- **Real PostgreSQL for atomicity, concurrency, and constraints.** Every fault-injection, concurrency, and tenant-isolation test runs against a real PostgreSQL instance — never SQLite — mirroring the AETS series' own established rule (e.g. [ATS-004 §4](../../accounting/tests/ATS-004-Journal-Posting-Test-Specification.md#4-test-strategy)), since these properties are exactly the ones a mocked or in-memory substitute cannot prove.
- **Genuine multi-process concurrency, not single-process simulation.** Every `TSK-004`/`TSK-012` concurrency test spawns two real OS processes via `proc_open` (`tests/bin/concurrent_task_*_worker.php`), mirroring the established pattern from Accounting Core's own concurrency tests (e.g. the P0/P1 `concurrent_issue_worker`/`concurrent_deallocate_worker` precedent) — a single PHPUnit process cannot reproduce the actual race window between reading state and writing it.
- **Five levels, not one.** A pure state-machine transition rule is proven once at the Unit level (fast, no I/O); the same behavior is not re-proven at Integration/Migration/HTTP/E2E level unless the level itself is what is being proven (e.g. database constraints, HTTP status codes, tenant isolation over a real session, or what actually renders in a browser).

## 5. Traceability matrix

| Invariant | Test IDs |
| --- | --- |
| TSK-001 | TSK-T022, TSK-T028, TSK-T034, TSK-T052, TSK-T053, TSK-T057 |
| TSK-002 | TSK-T001–TSK-T017, TSK-T021, TSK-T023, TSK-T024, TSK-T032, TSK-T049 |
| TSK-003 | TSK-T018, TSK-T020, TSK-T039, TSK-T050, TSK-T054–TSK-T056 |
| TSK-004 | TSK-T026, TSK-T033, TSK-T036 |
| TSK-005 | TSK-T042 |
| TSK-006 | TSK-T058 *(AI-producer half deferred — see §7.)* |
| TSK-007 | TSK-T025, TSK-T041, TSK-T046, TSK-T047, TSK-T054–TSK-T056 |
| TSK-008 | TSK-T013 *(partially proven — see §7)* |
| TSK-009 | TSK-T008, TSK-T017 |
| TSK-010 | TSK-T027, TSK-T028, TSK-T037, TSK-T038 |
| TSK-011 | TSK-T019, TSK-T029, TSK-T030, TSK-T037, TSK-T038 |
| TSK-012 | TSK-T031, TSK-T032, TSK-T033, TSK-T035, TSK-T036 |
| TSK-013 | TSK-T062–TSK-T070, TSK-T075, TSK-T076, TSK-T078, TSK-T079, TSK-T081 |
| TSK-014 | TSK-T059–TSK-T061, TSK-T071–TSK-T074, TSK-T077, TSK-T080, TSK-T082 |

## 6. Test cases

### 6.1 Unit — `Tests\Unit\Domain\Workspace\TaskTest`

Pure state-machine tests, no I/O. Proves `TSK-002` and `TSK-009` at the entity level; persistence, audit, and Accounting Core integration are proven separately at Integration/HTTP level.

| Test ID | Test method | Description |
| --- | --- | --- |
| TSK-T001 | `test_receive_starts_in_received_state` | A newly received Task starts in `Received`. |
| TSK-T002 | `test_full_happy_path_to_completed` | `Received → Processing → NeedsReview → Approved → Executing → Completed` succeeds end to end in memory. |
| TSK-T003 | `test_needs_information_and_resume_processing_round_trip` | `Processing → NeedsInformation → Processing` succeeds. |
| TSK-T004 | `test_executing_can_fail_with_a_reason` | `Executing → Failed` succeeds and carries the given reason. |
| TSK-T005 | `test_needs_review_can_be_rejected` | `NeedsReview → Rejected` succeeds. |
| TSK-T006 | `test_needs_review_can_be_superseded` | `NeedsReview → Superseded` succeeds. |
| TSK-T007 | `test_cancel_is_valid_from_every_pre_approval_state` (4 data sets) | `Cancel` succeeds from `Received`, `Processing`, `NeedsInformation`, and `NeedsReview`. |
| TSK-T008 | `test_cancel_is_rejected_once_approved` | `Cancel` is rejected once a Task has reached `Approved` (`TSK-009` — `Approved` is past the cancellable window, though not itself terminal). |
| TSK-T009 | `test_start_processing_only_valid_from_received` | `startProcessing()` is rejected from any state but `Received`. |
| TSK-T010 | `test_move_to_review_only_valid_from_processing` | `moveToReview()` is rejected from any state but `Processing`. |
| TSK-T011 | `test_approve_only_valid_from_needs_review` | `approve()` is rejected from any state but `NeedsReview`. |
| TSK-T012 | `test_start_executing_only_valid_from_approved` | `startExecuting()` is rejected from any state but `Approved`. |
| TSK-T013 | `test_complete_only_valid_from_executing` | `complete()` is rejected from any state but `Executing` — the only in-memory proof that a `Completed` Task accepts no further transition (`TSK-008`, partial; see §7). |
| TSK-T014 | `test_fail_only_valid_from_executing` | `fail()` is rejected from any state but `Executing`. |
| TSK-T015 | `test_reject_only_valid_from_needs_review` | `reject()` is rejected from any state but `NeedsReview`. |
| TSK-T016 | `test_transitions_never_mutate_the_original_instance` | Every transition method returns a new instance; the original is untouched. |
| TSK-T017 | `test_is_terminal_matches_wts_001_section_6_tsk_009` (11 data sets) | `TaskState::isTerminal()` agrees with WTS-001 §6 for every one of the 11 states (`TSK-009`). |
| TSK-T059 | `test_a_task_created_without_supersedes_task_id_carries_none` | An ordinarily-received Task's `supersedesTaskId()` is `null`. |
| TSK-T060 | `test_a_correction_task_carries_the_opaque_supersedes_task_id_it_was_created_with` | `Task::receive()`'s optional `$supersedesTaskId` argument is carried verbatim (`TSK-014`). |
| TSK-T061 | `test_supersedes_task_id_survives_every_transition` | `supersedesTaskId()` is unchanged across a full `Received → … → Completed` traversal — proving it is immutable state, not accidentally dropped by any one transition's `with()`/`complete()`/`fail()` constructor path. |

### 6.2 Integration — `Tests\Feature\Domain\Workspace\TaskServiceIntegrationTest`

Real PostgreSQL, real Accounting Core (`ExpenseRecordingService` et al.), no stubbing. Skipped with an explicit reason if no `pgsql` connection is reachable.

| Test ID | Test method | Description |
| --- | --- | --- |
| TSK-T018 | `test_submit_lands_the_task_in_needs_review_with_a_human_proposal` | `submit()` produces exactly one Task in `NeedsReview` and one Proposal record. |
| TSK-T019 | `test_submit_is_idempotent_under_the_same_idempotency_key` | A sequential retry under the same key, unchanged payload, returns the identical Task — no second row. |
| TSK-T020 | `test_approve_completes_the_task_and_posts_a_balanced_journal` | `approve()` posts a real, Posted Journal through Accounting Core (`TSK-003`). |
| TSK-T021 | `test_approve_is_rejected_once_already_rejected` | `approve()` on a `Rejected` Task raises `InvalidTaskStateTransitionException`. |
| TSK-T022 | `test_every_transition_in_the_happy_path_is_recorded_with_actor_and_reason` | The full `Received→…→Completed` sequence produces exactly the matching six-row transition history, every row carrying a non-empty actor (`TSK-001`). |
| TSK-T023 | `test_reject_requires_a_task_in_needs_review` | A second `reject()` call on an already-`Rejected` Task fails. |
| TSK-T024 | `test_cancel_is_rejected_after_completion` | `cancel()` on a `Completed` Task fails. |
| TSK-T025 | `test_a_task_created_under_one_tenant_is_invisible_to_another` | `approve()` against another Tenant's Task raises `TaskNotFoundException`. |
| TSK-T026 | `test_two_concurrent_approve_attempts_never_both_succeed` | Genuine two-process race on `approve()` from `NeedsReview`: exactly one reaches `Completed`, the other is safely rejected (`TaskAlreadyTransitionedException` or `InvalidTaskStateTransitionException`, both safe outcomes — see the test's own docblock). 5 consecutive clean runs required before this test was considered complete. |
| TSK-T027 | `test_a_forced_proposal_insert_failure_rolls_back_the_entire_submit_transaction` | A forced, non-duplicate `CHECK` constraint failure on the `proposals` insert rolls back the entire `submit()` sequence — zero `tasks`/`task_transitions` rows afterward. |
| TSK-T028 | `test_a_forced_transition_insert_failure_rolls_back_the_state_update_too` | A forced, non-duplicate `CHECK` constraint failure on the `task_transitions` insert rolls back the paired `tasks.state` compare-and-swap `UPDATE` — `state` never disagrees with its own (now-absent) transition record (`TSK-001`). |
| TSK-T029 | `test_submit_with_a_conflicting_payload_under_the_same_idempotency_key_is_rejected` | A retry under the same key with a materially different amount raises `TaskSubmissionConflictException`. |
| TSK-T030 | `test_submit_with_an_unchanged_payload_under_the_same_idempotency_key_replays` | A retry under the same key with an unchanged payload returns the identical Task; still exactly one `tasks` row. |
| TSK-T031 | `test_resume_completes_a_task_stranded_in_executing` | A Task force-written to `Executing` (simulating a crash) is recovered to `Completed` with a real posted Journal by `resume()`. |
| TSK-T032 | `test_resume_is_rejected_from_a_non_executing_state` | `resume()` on a `NeedsReview` Task raises `InvalidTaskStateTransitionException`. |
| TSK-T033 | `test_two_concurrent_resume_attempts_never_both_succeed` | Genuine two-process race on `resume()` from a stranded `Executing` Task: exactly one completes, the other is safely rejected. 5 consecutive clean runs required. |
| TSK-T034 | `test_the_domain_supplied_transition_id_is_persisted_verbatim` | `TaskRepository::recordTransition()` persists the domain-constructed `TaskTransition`'s own id, not a freshly generated one. |
| TSK-T035 | `test_approve_recovers_a_task_stranded_in_approved` | A Task force-written to `Approved` (simulating a crash between `NeedsReview→Approved` and `Approved→Executing`) is recovered to `Completed` by calling `approve()` again — no separate recovery method needed. |
| TSK-T036 | `test_two_concurrent_approve_attempts_on_a_stranded_approved_task_never_both_succeed` | Genuine two-process race on `approve()` from a stranded `Approved` Task: exactly one completes, the other is safely rejected. 5 consecutive clean runs required. |
| TSK-T037 | `test_two_concurrent_first_submissions_with_matching_payload_both_resolve_to_the_same_task` | Genuine two-process race on `submit()` itself, under a *never-before-used* key, identical payload: both processes resolve to the identical Task; exactly one `tasks` row. |
| TSK-T038 | `test_two_concurrent_first_submissions_with_conflicting_payload_reject_the_loser` | The same race with a materially different payload: exactly one process succeeds, the other observes `TaskSubmissionConflictException` — never a raw, unhandled constraint-violation error. |
| TSK-T062 | `test_save_for_later_completion_lands_the_task_in_needs_information_with_a_draft_and_no_proposal` | `saveForLaterCompletion()` lands the Task in `NeedsInformation` with exactly one `task_drafts` row and zero `proposals` rows (`TSK-013`). |
| TSK-T063 | `test_save_for_later_completion_is_idempotent_under_the_same_idempotency_key` | A retry under the same key, unchanged Draft payload, returns the identical Task — no second row. |
| TSK-T064 | `test_save_for_later_completion_with_a_conflicting_payload_under_the_same_key_is_rejected` | A retry under the same key with a materially different amount raises `TaskSubmissionConflictException`. |
| TSK-T065 | `test_submit_reused_on_a_key_already_deferred_is_rejected_as_conflicting` | `submit()` retried under a key already used for a deferred submission is rejected as conflicting rather than crashing on the absent Proposal — the defensive guard `replayOrConflict()` added for exactly this case. |
| TSK-T066 | `test_a_forced_task_draft_insert_failure_rolls_back_the_entire_save_for_later_transaction` | A forced, non-duplicate constraint failure on the `task_drafts` insert rolls back the entire `saveForLaterCompletion()` sequence — mirrors `TSK-T027` for the deferred path (`TSK-010` extended). |
| TSK-T067 | `test_provide_information_completes_the_deferred_task_into_needs_review_with_a_real_proposal` | `provideInformation()` performs `NeedsInformation → Processing → NeedsReview` atomically, producing a real Proposal from the retained Draft fields plus the newly supplied Accounts; the completed Proposal approves and posts exactly like any other. |
| TSK-T068 | `test_provide_information_is_rejected_from_a_non_needs_information_state` | `provideInformation()` on a Task already `NeedsReview` raises `InvalidTaskStateTransitionException`. |
| TSK-T069 | `test_provide_information_with_an_unresolved_account_reference_leaves_the_task_in_needs_information` | An unresolved Account reference at completion time rolls back the whole attempt — the Task remains `NeedsInformation`, its Draft intact, zero Proposal created. |
| TSK-T070 | `test_two_concurrent_provide_information_attempts_never_both_succeed` | Genuine two-process race on `provideInformation()` from the same `NeedsInformation` Task: exactly one reaches `NeedsReview`, the other is safely rejected (`TSK-004` extended, pessimistic lock per `TSK-T033`'s own reasoning). |
| TSK-T071 | `test_supersede_and_submit_correction_supersedes_the_original_and_creates_a_linked_replacement` | `supersedeAndSubmitCorrection()` moves the original to `Superseded` and creates a `NeedsReview` replacement carrying the original's id as `supersedesTaskId` (`TSK-014`); the replacement approves and posts exactly like any other Task. |
| TSK-T072 | `test_supersede_and_submit_correction_is_rejected_from_a_non_needs_review_state` | Attempting to supersede an already-`Rejected` Task raises `InvalidTaskStateTransitionException`. |
| TSK-T073 | `test_supersede_and_submit_correction_with_a_rejected_account_reference_leaves_the_original_in_needs_review` | A correction rejected for an unresolved Account reference rolls back the supersede too — the original Task is found exactly as it was, still `NeedsReview`, with no replacement row created. |
| TSK-T074 | `test_two_concurrent_supersede_attempts_on_the_same_task_never_both_succeed` | Genuine two-process race on `supersedeAndSubmitCorrection()` against the same `NeedsReview` Task: exactly one correction succeeds, the other is safely rejected (`TSK-004` extended to the optimistic CAS `supersede()` itself uses). |

### 6.3 HTTP — `Tests\Feature\Http\Api\IdentityAndAccountingApiTest`

Real HTTP requests (Sanctum SPA session, real CSRF handshake) against a real PostgreSQL instance.

| Test ID | Test method | Description |
| --- | --- | --- |
| TSK-T039 | `test_a_task_submitted_and_approved_over_http_posts_a_journal` | `POST /tasks` then `POST /tasks/{id}/approve` posts a real Journal, reachable end to end over HTTP. |
| TSK-T040 | `test_a_task_can_be_rejected_with_a_reason` | `POST /tasks/{id}/reject` with a reason moves the Task to `Rejected`. |
| TSK-T041 | `test_a_tenants_tasks_are_never_visible_to_another_tenant` | A second Tenant's session receives `404` on both `GET /tasks/{id}` and `POST /tasks/{id}/approve` for the first Tenant's Task; the underlying row count is unaffected. |
| TSK-T042 | `test_a_task_approved_after_its_period_closed_fails_without_posting` | A Task submitted while its financial date is open, then approved *after* the Period closes through that date, transitions to `Failed` with a reason — never posts (`TSK-005`). |
| TSK-T058 | `test_every_protected_route_rejects_an_unauthenticated_request` | Every non-public `/api/v1` route is structurally required to carry `auth:sanctum`; every tenant-owned route must also carry `tenant.resolved`; an unauthenticated Task approval request receives `401` before Task lookup or transition. |
| TSK-T075 | `test_a_task_can_defer_the_account_decision_and_later_provide_information_over_http` | `POST /tasks` without either Account reference lands `NeedsInformation`; `GET /tasks/{id}` shows the retained Draft and a `null` Proposal; `POST /tasks/{id}/provide-information` completes it to `NeedsReview` with a real Proposal, reachable end to end over HTTP. |
| TSK-T076 | `test_submitting_a_task_with_exactly_one_account_reference_is_rejected` | Supplying only one of the two Account references is a `422` — `StoreTaskRequest`'s both-or-neither rule. |
| TSK-T077 | `test_a_task_under_review_can_be_edited_via_supersede_over_http` | `POST /tasks/{id}/supersede` supersedes the original and returns the linked `201` correction; the original's own `GET` afterward shows `Superseded`. |

### 6.4 End-to-end — `apps/web/tests/e2e/*.spec.ts`

Real Chromium browser (Playwright) against the live Docker Compose stack — the real UI, real clicks, real HTTP requests, never a mocked API layer.

| Test ID | Spec file : test name | Description |
| --- | --- | --- |
| TSK-T043 | `workspace-task.spec.ts` : *submitting a Task lands it in NeedsReview with its Proposal detail* | The Work Queue composer submits a Task; Task Detail shows the correct Proposal. |
| TSK-T044 | `workspace-task.spec.ts` : *confirming a Task posts a Journal and shows the full transition history* | Clicking "Confirm and post" reaches `Completed` with the full six-step transition history rendered. |
| TSK-T045 | `workspace-task.spec.ts` : *rejecting a Task requires a reason and moves it to Rejected* | The Reject flow requires a reason and reaches `Rejected`. |
| TSK-T046 | `workspace-task.spec.ts` : *a Task submitted under one tenant never appears in another tenant's Work Queue* | A second, freshly registered Tenant's Work Queue is empty. |
| TSK-T047 | `workspace-task.spec.ts` : *a Task is not directly loadable by URL from a different, authenticated tenant* | Direct-object proof: navigating straight to Tenant A's Task URL while authenticated as Tenant B shows "Failed to load this Task," never the Proposal detail — a materially stronger proof than TSK-T046 alone, since a listing omission would not by itself catch a route that forgot its own tenant check. |
| TSK-T081 | `needs-information-and-supersede.spec.ts` : *deferring the account decision lands the task in NeedsInformation, and providing it later completes the task* | The Home composer's "Not sure which accounts yet? Decide later." affordance submits without Accounts; Task Detail's "Needs more information" panel completes it to `NeedsReview`, which then confirms and posts. |
| TSK-T082 | `needs-information-and-supersede.spec.ts` : *editing a task under review supersedes it and navigates to the linked correction* | Task Detail's "Edit" action on a `NeedsReview` Task pre-fills a correction form; saving it supersedes the original (visiting its own URL afterward shows `Superseded`) and navigates to the new, linked correction. |

### 6.5 Migration — `Tests\Feature\Infrastructure\Workspace\WorkspaceTablesMigrationTest`

Each case executes the real production migrations inside a dedicated PostgreSQL schema. The schema is discarded after every test, so reversibility and rejected writes cannot alter shared application or development data.

| Test ID | Test method | Description |
| --- | --- | --- |
| TSK-T048 | `test_workspace_migrations_apply_and_reverse_cleanly` | All four Workspace migrations apply in production order, expose their required columns, and reverse in dependency-safe order with no Workspace table left behind. |
| TSK-T049 | `test_task_rejects_a_noncanonical_state` | PostgreSQL rejects a Task state outside the complete `TaskState` enum. |
| TSK-T050 | `test_proposal_rejects_a_noncanonical_command_type` | PostgreSQL rejects a Proposal command outside the closed `CommandType` set. |
| TSK-T051 | `test_proposal_rejects_a_noncanonical_producer_type` | PostgreSQL rejects a Proposal producer outside the canonical Human/AI set; this proves storage vocabulary only, not the deferred AI authorization path. |
| TSK-T052 | `test_transition_rejects_a_noncanonical_to_state` | PostgreSQL rejects a noncanonical transition destination. |
| TSK-T053 | `test_transition_rejects_a_noncanonical_from_state` | PostgreSQL rejects a noncanonical non-null transition origin. |
| TSK-T054 | `test_proposal_cannot_reference_another_tenants_task` | The composite foreign key rejects cross-Tenant Task correlation. |
| TSK-T055 | `test_proposal_cannot_reference_another_tenants_account` | The composite foreign keys reject cross-Tenant Account correlation. |
| TSK-T056 | `test_task_cannot_reference_another_tenants_journal` | The composite foreign key rejects a cross-Tenant result Journal. |
| TSK-T057 | `test_transition_sequence_is_generated_always_and_cannot_be_caller_supplied` | PostgreSQL rejects a caller-supplied audit ordering value; sequence ownership remains exclusively with the database. |
| TSK-T078 | `test_task_draft_rejects_a_noncanonical_command_type` | PostgreSQL rejects a Task Draft command outside the closed `CommandType` set. |
| TSK-T079 | `test_task_draft_cannot_reference_another_tenants_task` | The composite foreign key rejects cross-Tenant Task correlation for `task_drafts`. |
| TSK-T080 | `test_task_cannot_supersede_another_tenants_task` | The composite, self-referential `(tenant_id, supersedes_task_id)` foreign key rejects cross-Tenant correlation — the same tenant-isolation discipline every other Workspace foreign key already enforces (`TSK-007`). |

## 7. TSK-006 and TSK-008 — partially deferred

**TSK-006** ("An AI-originated Proposal may only be written by an authorized AI Orchestration producer into a `NeedsReview`-or-earlier Task state. Only an authenticated human actor's explicit action may perform `NeedsReview`→`Approved`.") is only half testable today. The second half is now isolated by `TSK-T058`: every non-public API route is structurally protected by Sanctum, every tenant-owned route also requires Tenant resolution, and an unauthenticated approval request receives `401` before Task lookup or transition. The first half — that AI cannot write a Proposal — has no code path to test at all: no AI Orchestration producer exists yet (WTS-000 §5, WTS-004 deferred). Mirrors [ATS-010 §7](../../accounting/tests/ATS-010-Audit-Trail-Test-Specification.md#7-aud-007--not-independently-testable)'s own treatment of `AUD-007` exactly: enforcement belongs to whichever future component actually produces AI Proposals, not to a test asserting the absence of code that does not exist.

**TSK-008**'s first clause ("A `Completed` Task is never re-opened") is proven at the state-machine level (`TSK-T013`: `complete()` itself is unreachable from `Completed`) but not independently proven that *no other code path* could mutate a Completed Task's row — `TaskRepository`'s own public surface (`record`, `updateState`, `transitionIfInState`, `recordTransition`) offers no state-specific guard of its own, relying entirely on `TaskService`'s own transition methods, which are proven. The second clause ("a correction ... creates a new Task referencing the original") is **still unbuilt for the `Completed` case specifically** — no code path exists to correct an already-`Completed` Task, so no test can prove that case without inventing behavior WTS-001 does not yet define at the implementation level.

**Not the same gap as TSK-014.** WTS-001 v3.0.0 (2026-09-16) built the analogous correction-linking mechanism for a *different* transition — `NeedsReview → Superseded`, which §4 had always listed as valid but which, until v3.0.0, no code ever reached (see `TSK-T059`–`TSK-T061`, `TSK-T071`–`TSK-T074`, `TSK-T077`, `TSK-T080`, `TSK-T082`). TSK-014 does not correct a `Completed` Task and does not close TSK-008's own second clause — a Task already `Completed` remains just as un-correctable today as before. Both gaps continue to reflect this repository's own governance discipline (flag ambiguity or missing implementation, never invent a test for behavior that does not exist) — a future WTS document defining a `Completed`-Task correction mechanism must extend this specification with real test IDs at that time.

## 8. Deferred Items

- AI-produced Proposal intake — deferred to the future WTS-004; see §7 for TSK-006.
- A correction mechanism for an already-`Completed` Task (TSK-008's own second clause) — see §7's distinction from TSK-014, which closes only the `NeedsReview` case.

## Changelog

- **1.3.0 (2026-09-16):** Adds `TSK-T059`–`TSK-T082`, proving WTS-001 v3.0.0's two new invariants across all five test levels: `TSK-013` (the deferred-Account-decision `NeedsInformation` path — Task Draft creation, idempotency and conflict detection, completion into a real Proposal, atomicity under fault injection, and a genuine two-process concurrency proof) and `TSK-014` (the edit/supersede correction path — linked replacement creation, atomicity of the supersede-and-submit pair under an Account-reference rejection, cross-Tenant isolation of the new self-referential foreign key, and a genuine two-process concurrency proof). §7 and §8 updated to distinguish TSK-014's now-closed `NeedsReview` gap from TSK-008's own still-open `Completed`-case gap. TSK-001–TSK-012's own test coverage is unchanged.
- **1.2.0 (2026-09-13):** Adds `TSK-T058`, converting the authenticated-human half of `TSK-006` from implicit coverage into an explicit route-structure and unauthenticated-approval test. AI-producer authorization remains deferred until that producer exists.
- **1.1.0 (2026-09-09):** Adds `TSK-T048`–`TSK-T057`, closing the recorded Workspace migration-assurance gap with isolated real-PostgreSQL proof of forward/reverse execution, canonical enum constraints, tenant-safe composite foreign keys, and database-only audit sequence assignment.
- **1.0.0 (2026-09-09):** Initial version, authored after WTS-001 v2.0.0's implementation (Phase D "Non-AI Workflow Shell" plus its subsequent reliability closure), per WTS-001 §8's own requirement and a second post-implementation QA pass's explicit request to close this governance gap.
