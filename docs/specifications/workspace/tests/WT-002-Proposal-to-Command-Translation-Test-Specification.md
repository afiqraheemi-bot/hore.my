# WT-002: Proposal-to-Command Translation Test Specification

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-09
- Owner: Workspace and Task (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [WTS-002](../WTS-002-Proposal-to-Command-Translation.md); [WTS-001](../WTS-001-Task-Proposal-State-Model.md); [AETS-003](../../accounting/AETS-003-Money-Specification.md); [AETS-007](../../accounting/AETS-007-Posting-Command.md); [AETS-014](../../accounting/AETS-014-Period-Management.md)

## 1. Purpose

This document traces every `PTC-NNN` invariant in WTS-002 to a concrete automated test. It tests the Workspace translation boundary itself while relying on the existing Accounting test series for the destination services' internal invariants. It does not claim that duplicated lower-level coverage is a Workspace test.

## 2. Test strategy

- Exact financial effects, persistence, rollback, tenant constraints, and Period behavior run against real PostgreSQL.
- HTTP tests prove malformed input and unsupported types are rejected before a Proposal is created.
- Structural tests protect the module boundary and the current closed Command set.
- Existing WTS-001 tests remain authoritative for state-machine concurrency and recovery; they are referenced where WTS-002 relies on the same behavior.

## 3. Traceability matrix

| Invariant | Test IDs |
| --- | --- |
| PTC-001 | PTC-T001, PTC-T012 |
| PTC-002 | PTC-T001, PTC-T009 |
| PTC-003 | PTC-T002 |
| PTC-004 | PTC-T002, PTC-T007 |
| PTC-005 | PTC-T001 |
| PTC-006 | PTC-T002, PTC-T008 |
| PTC-007 | PTC-T003, PTC-T004, PTC-T005, PTC-T006, PTC-T007 |
| PTC-008 | PTC-T003, PTC-T006, PTC-T008 |
| PTC-009 | PTC-T010 |
| PTC-010 | PTC-T009, PTC-T011 |
| PTC-011 | PTC-T013 |
| PTC-012 | PTC-T002, PTC-T012, PTC-T013 |

## 4. Test cases

### 4.1 Real-PostgreSQL integration

Class: `Tests\Feature\Domain\Workspace\TaskServiceIntegrationTest`

| Test ID | Test method | Proof |
| --- | --- | --- |
| PTC-T001 | `test_every_proposal_command_type_maps_accounts_to_the_correct_journal_sides` (5 data sets) | Expense, Income, Transfer, Contribution, and Drawing each call their existing Recording Service, persist the correct business record, and produce the exact debit/credit Account mapping and exact minor-unit amount. |
| PTC-T002 | `test_proposal_common_fields_approving_actor_and_identity_propagate_unchanged` | Tenant, Money, Currency, financial date, description, Evidence Reference, approving Actor, Journal linkage, and Proposal-derived Posting Idempotency Key survive translation unchanged. |
| PTC-T003 | `test_destination_validation_rejections_fail_the_task_without_posting` (4 data sets) | Wrong Account type, inactive Account, non-posting-eligible Account, and same-Account Transfer are revalidated by destination services; each reaches `Failed` with a real reason and zero Journal. |
| PTC-T004 | `test_cross_tenant_account_is_rejected_atomically_during_proposal_submission` | A composite Tenant/Account FK rejects another Tenant's Account before Proposal creation; the entire Task submission rolls back and exposes only the common unresolved-Account category. |
| PTC-T005 | `test_a_task_approved_after_its_period_closed_fails_without_posting` in `IdentityAndAccountingApiTest` | Approval re-checks the current Period watermark and fails without posting when the Proposal date became closed after submission. |
| PTC-T006 | `test_unexpected_persistence_failure_propagates_and_rolls_back_approval` | A forced PostgreSQL failure is not disguised as a business rejection; approval rolls back to `NeedsReview` with no business record or Journal. |
| PTC-T008 | `test_resume_completes_a_task_stranded_in_executing` and `test_approve_recovers_a_task_stranded_in_approved` | Recovery reuses the deterministic Proposal-derived command identity and completes through the same execution boundary. Accounting Core's dedicated ATS-007 tests remain authoritative for byte-for-byte replay/conflict semantics. |

### 4.2 HTTP boundary

Class: `Tests\Feature\Http\Api\IdentityAndAccountingApiTest`

| Test ID | Test method | Proof |
| --- | --- | --- |
| PTC-T005 | `test_a_task_approved_after_its_period_closed_fails_without_posting` | Closed-Period behavior is observable through the authenticated product API. |
| PTC-T007 | `test_a_task_rejects_malformed_money_before_creating_a_proposal` | Malformed Money receives 422 and creates neither Task nor Proposal. Money's exhaustive grammar remains owned by ATS-003. |
| PTC-T011 | `test_a_task_rejects_an_unsupported_command_type` | A type outside the approved five receives 422 and cannot enter persistence or translation. |
| PTC-T012 | `test_a_task_submitted_and_approved_over_http_posts_a_journal` | Only the explicit authenticated approval endpoint moves the human Proposal through execution to a Journal. |

### 4.3 Structural boundary

Class: `Tests\Unit\Domain\Workspace\WorkspaceTranslationBoundaryTest`

| Test ID | Test method | Proof |
| --- | --- | --- |
| PTC-T009 | `test_translation_delegates_to_existing_recording_services_only` | TaskService depends on the four services representing the five approved mappings and does not add Invoice or Payment execution. |
| PTC-T010 | `test_workspace_has_no_direct_accounting_persistence_dependency` | No Accounting infrastructure repository, DB facade, query builder, or Accounting-owned table write exists in the translation service. |
| PTC-T011 | `test_translation_command_type_set_is_explicitly_closed` | The enum contains exactly the five approved types; adding another changes a failing test intentionally. |
| PTC-T013 | `test_current_translation_has_no_ai_or_source_fingerprint_authority` | Current submission is explicitly Human; TaskService contains neither an AI producer path nor Source Fingerprint fabrication. |

## 5. Lower-level validation ownership

WTS-002 requires identical validation to manual entry, not a second validator. The Expense, Income, Transfer, and Owner Equity integration suites independently prove unresolved/cross-Tenant, inactive, non-posting-eligible, wrong-type, replay, and conflicting-reuse behavior for their public Recording Services. `PTC-T001` and `PTC-T003` prove Workspace calls those same services and observes those validators. ATS-003, ATS-007, and ATS-014 remain authoritative for exhaustive Money, posting-idempotency, atomicity, and Period cases.

## 6. Deferred coverage

- AI-produced Proposals cannot be behavior-tested until WTS-004 authorizes and defines a producer. PTC-T013 protects the current absence of that authority.
- External Evidence ownership, source hashing, and Source Fingerprint deduplication remain deferred by WTS-002 and AETS-010. No test invents an Evidence aggregate.
- Proposal editing/supersession has no approved application contract and is not tested here.

These are scope deferrals, not unreported failures of the current five-type translation contract.

## Changelog

- **1.0.0 (2026-09-09):** Initial traceability baseline for WTS-002, covering all twelve invariants across real-PostgreSQL integration, authenticated HTTP, structural boundaries, and explicitly named lower-level authoritative suites.
