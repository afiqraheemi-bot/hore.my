# WT-003: Task Audit Trail & Evidence Correlation Test Specification

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-09
- Owner: Workspace and Task (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [WTS-003](../WTS-003-Task-Audit-Trail-Evidence-Correlation.md); [WT-001](WT-001-Task-Proposal-State-Model-Test-Specification.md); [WT-002](WT-002-Proposal-to-Command-Translation-Test-Specification.md); [ATS-010](../../accounting/tests/ATS-010-Audit-Trail-Test-Specification.md)

## 1. Purpose and strategy

This document traces every `TAC-NNN` invariant to concrete automated evidence. Database ordering, foreign-key correlation, atomicity, and tenant claims run on real PostgreSQL. HTTP tests prove the authenticated tenant boundary, while structural tests prove the audit stores remain independently owned.

## 2. Traceability

| Invariant | Tests |
| --- | --- |
| TAC-001 | TAC-T001, TAC-T002 |
| TAC-002 | TAC-T001, TAC-T003 |
| TAC-003 | TAC-T001, TAC-T003, existing WT-001 TSK-T022 |
| TAC-004 | TAC-T003, TAC-T004 |
| TAC-005 | TAC-T003, TAC-T005 |
| TAC-006 | TAC-T006, TAC-T007 |
| TAC-007 | TAC-T008, TAC-T009 |
| TAC-008 | TAC-T010, existing WT-001 TSK-T041/TSK-T046/TSK-T047 |
| TAC-009 | TAC-T006, TAC-T007 |

## 3. Test cases

| ID | Concrete test | Proof |
| --- | --- | --- |
| TAC-T001 | `TaskServiceIntegrationTest::test_every_transition_in_the_happy_path_is_recorded_with_actor_and_reason` | Forces all lifecycle timestamps equal, then proves the repository still reconstructs the six transitions in causal order using unique increasing sequence values. |
| TAC-T002 | `TaskServiceIntegrationTest::test_transition_sequence_is_non_nullable_unique_and_database_assigned` | PostgreSQL metadata proves the ordering column is a non-null `GENERATED ALWAYS AS IDENTITY` with a unique index. |
| TAC-T003 | `TaskServiceIntegrationTest::test_proposal_common_fields_approving_actor_and_identity_propagate_unchanged` | Proves same Tenant, exact Journal, Accounting Audit Event/action/approving Actor, Proposal Evidence, three evidence-bearing capture transitions, and Journal Evidence Link correlate exactly. |
| TAC-T004 | `TaskServiceIntegrationTest::test_approve_completes_the_task_and_posts_a_balanced_journal` | Completed Task holds the posted Journal and the no-evidence path creates zero linkage rows. |
| TAC-T005 | The evidence-present assertions in TAC-T003 and no-evidence assertion in TAC-T004 | Exact opacity and no fabrication. |
| TAC-T006 | `WorkspaceTranslationBoundaryTest::test_workspace_has_no_direct_accounting_persistence_dependency` | Workspace has no Accounting repository/table write path. |
| TAC-T007 | `WorkspaceTranslationBoundaryTest::test_accounting_core_has_no_dependency_on_workspace_audit_records` | Accounting Domain source contains no Workspace dependency or `task_transitions` access. |
| TAC-T008 | `TaskServiceIntegrationTest::test_destination_validation_rejections_fail_the_task_without_posting` | Designed rejection records `Failed` with a reason and no Journal. |
| TAC-T009 | `TaskServiceIntegrationTest::test_unexpected_persistence_failure_propagates_and_rolls_back_approval` | Unexpected persistence failure leaves the Task at `NeedsReview` and creates no business record or Journal. |
| TAC-T010 | `IdentityAndAccountingApiTest::test_a_tenants_tasks_are_never_visible_to_another_tenant` plus E2E direct-URL isolation | Another authenticated Tenant cannot list or load the Task, Proposal, transitions, result, or evidence detail. |

## 4. Deferred coverage

The combined Accounting Audit query/export API and operator UI are expressly deferred by WTS-003 and AETS-010. Evidence tenant ownership awaits Document Processing's persisted Evidence aggregate. AI provenance awaits WTS-004. No test here claims those absent capabilities.

## Changelog

- **1.0.0 (2026-09-09):** Initial complete traceability for WTS-003's currently implementable persistence and module-boundary contract.
