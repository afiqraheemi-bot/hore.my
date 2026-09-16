# ATS-008: Bank Import, Matching & Reconciliation Test Specification

- Status: Draft
- Version: 0.5.0
- Effective date: Not effective — pending AETS-008 activation
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-008](../AETS-008-Bank-Reconciliation.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0007](../../../adr/0007-money-representation-strategy.md)

## 1. Purpose and status warning

This Draft inventories current Banking proof and names missing acceptance evidence. It is not a release certificate. IDs marked `Existing` map to executable tests already in the repository; IDs marked `Required` must be implemented against the decisions AETS-008 §12 now records (as of v0.2.0), not invented independently; IDs marked `Deferred` test a capability §12 has explicitly placed out of this Draft's Active-version scope and do not gate Activation.

All integration and schema tests require real PostgreSQL and must run with skip/warning/risky/deprecation failures enabled.

## 2. Current executable evidence

### 2.1 Import

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T001 | Existing | Well-formed file imports exact rows and counts. | `a_well_formed_statement_is_imported_and_normalized_exactly` |
| BNK-T002 | Existing | Byte-identical replay returns the original batch. | `reuploading_the_byte_identical_file_replays_instead_of_duplicating` |
| BNK-T003 | Existing | Overlapping re-export skips duplicate natural-key rows. | `an_overlapping_non_identical_reexport_skips_only_the_duplicate_rows` |
| BNK-T004 | Existing | Malformed file is atomic with zero rows/batches. | `a_malformed_statement_is_rejected_and_persists_nothing` |
| BNK-T005 | Existing | Identical input is independent between Tenants. | `two_tenants_importing_do_not_interfere` |
| BNK-T006 | Existing | Identical input is independent between Bank Accounts. | `two_bank_accounts_with_identical_file_content_do_not_interfere` |
| BNK-T007 | Existing | Forced transaction-row failure rolls back the whole import. | `a_forced_bank_transaction_insert_failure_rolls_back_the_entire_import` |

### 2.2 Matching

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T008 | Existing | Exact eligible Expense is suggested. | `a_bank_transaction_is_suggested_against_a_matching_expense` |
| BNK-T009 | Existing | Human confirmation persists one immutable Match. | `confirming_a_suggested_match_persists_it` |
| BNK-T010 | Existing | Confirmed Bank Transaction is not suggested again. | `a_confirmed_match_is_never_suggested_again` |
| BNK-T011 | Existing | Reconfirming an already-matched Bank Transaction is rejected. | `confirming_an_already_matched_bank_transaction_is_rejected` |
| BNK-T012 | Existing | A noncandidate Journal is rejected at confirmation. | `confirming_a_journal_that_is_not_a_valid_candidate_is_rejected` |
| BNK-T013 | Existing | Amount mismatch produces no candidate. | `amount_mismatch_produces_no_candidate` |

### 2.3 Reconciliation lifecycle

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T014 | Existing | Opening starts Draft and persists. | `opening_a_reconciliation_starts_in_draft` |
| BNK-T015 | Existing | Created time survives exact reload. | `created_at_is_persisted_and_survives_a_reload` |
| BNK-T016 | Existing | Exact imported activity produces zero difference. | `difference_is_zero_when_imported_transactions_reconcile_exactly` |
| BNK-T017 | Existing | Missing activity produces exact nonzero difference. | `difference_is_nonzero_when_a_transaction_is_missing` |
| BNK-T018 | Existing | Rows outside the inclusive period are excluded. | `transactions_outside_the_period_are_excluded_from_the_difference` |
| BNK-T019 | Existing | Full Draft→InReview→Balanced→Completed path succeeds at zero. | `full_happy_path_lifecycle_end_to_end` |
| BNK-T020 | Existing | Nonzero difference cannot become Balanced. | `marking_balanced_with_a_nonzero_difference_is_rejected` |
| BNK-T021 | Existing | Completion recomputes live difference and rejects stale Balanced state. | `completion_rechecks_the_live_difference_instead_of_trusting_stale_balanced_state` |
| BNK-T022 | Existing | Reopen requires a reason. | `reopen_requires_a_non_empty_reason` |
| BNK-T023 | Existing | Reopen returns Draft and appends exact history. | `reopen_persists_a_history_record_and_returns_to_draft` |
| BNK-T024 | Existing | Repeated reopen cycles preserve every history row. | `reopening_twice_persists_two_history_records` |
| BNK-T025 | Existing | Forced history failure rolls back the state change. | `a_forced_reopening_history_failure_rolls_back_the_state_change` |

### 2.4 Schema tenant isolation

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T026 | Existing | A full same-Tenant relationship chain is accepted. | `a_valid_same_tenant_relationship_chain_is_accepted` |
| BNK-T027 | Existing | Import Batch rejects another Tenant's Bank Account. | `import_batch_cannot_reference_another_tenants_bank_account` |
| BNK-T028 | Existing | Bank Transaction rejects another Tenant's Bank Account. | `bank_transaction_cannot_reference_another_tenants_bank_account` |
| BNK-T029 | Existing | Bank Transaction rejects a batch for another Bank Account. | `bank_transaction_cannot_reference_an_import_batch_for_another_bank_account` |
| BNK-T030 | Existing | Match rejects another Tenant's Bank Transaction. | `match_cannot_reference_another_tenants_bank_transaction` |
| BNK-T031 | Existing | Reconciliation rejects another Tenant's Bank Account. | `reconciliation_cannot_reference_another_tenants_bank_account` |
| BNK-T032 | Existing | Reopening rejects another Tenant's Reconciliation. | `reopening_cannot_reference_another_tenants_reconciliation` |
| BNK-T033 | Existing | Tenant-hardening migration reverses and reapplies. | `migration_reverses_and_reapplies_cleanly` |
| BNK-T034 | Existing | Banking HTTP boundaries fail closed across Tenants without changing records. | `banking_endpoints_do_not_expose_or_accept_another_tenants_records` |

### 2.5 Exact values and lifecycle persistence

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T035 | Existing | Approved valid exact Banking facts are accepted. | `valid_exact_banking_facts_are_accepted` |
| BNK-T036 | Existing | Import counts cannot be negative. | `import_counts_cannot_be_negative` |
| BNK-T037 | Existing | Import row count equals inserted plus duplicate count. | `import_counts_must_reconcile_exactly` |
| BNK-T038 | Existing | Bank Transaction amount magnitude cannot be negative. | `bank_transaction_amount_cannot_be_negative` |
| BNK-T039 | Existing | Bank Transaction currency is MYR. | `bank_transaction_currency_must_be_myr` |
| BNK-T040 | Existing | Reconciliation currency is MYR. | `reconciliation_currency_must_be_myr` |
| BNK-T041 | Existing | `Completed` requires `completed_at`. | `completed_reconciliation_requires_completed_at` |
| BNK-T042 | Existing | Non-completed state rejects stale `completed_at`. | `noncompleted_reconciliation_cannot_carry_completed_at` |
| BNK-T043 | Existing | Reopening reason cannot be blank. | `reopening_reason_cannot_be_blank` |
| BNK-T044 | Existing | Financial-integrity migration reverses and reapplies. | `migration_reverses_and_reapplies_cleanly` |

### 2.6 Lifecycle concurrency

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T048 | Existing | Genuine two-process races across every lifecycle edge permit exactly one transition, preserve the final state, and append exactly one reopening-history row. | `concurrent_lifecycle_transitions_cannot_overwrite_or_skip_state` |
| BNK-T056 | Existing | Every Banking route requires authentication; every write boundary rejects invalid payloads without persistence; malformed identifiers fail as validation errors and canonical missing identifiers fail closed. | `every_banking_route_rejects_unauthenticated_access`; `banking_write_routes_reject_invalid_payloads_without_persistence`; `banking_routes_fail_deterministically_for_malformed_and_missing_identifiers` |

The existing Bank Account and Bank Transaction migration test classes additionally prove table shape, canonical direction, file-hash/natural-key uniqueness, basic foreign keys, exact valid inserts, and their original migration rollback paths.

### 2.7 Policy-resolved assurance (2026-09-16, `BNK-014`–`BNK-020`)

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T045 | Existing | Two concurrent identical file imports produce one deterministic result. | `test_concurrent_identical_imports_remain_atomic_and_duplicate_free` |
| BNK-T046 | Existing | Concurrent overlapping exports cannot produce duplicate natural-key rows or partial batches. | `test_concurrent_overlapping_reexports_cannot_create_duplicates_or_partial_batches` |
| BNK-T047 | Existing | Concurrent confirmations for one Bank Transaction: identical replays, differing gets an explicit conflict, exactly one Match persists. | `test_reconfirming_the_same_pair_replays_the_existing_match`; `test_confirming_a_different_journal_for_an_already_matched_bank_transaction_conflicts`; `test_concurrent_confirmation_of_the_same_bank_transaction_against_different_journals_conflicts` |
| BNK-T049 | Existing | A Completed Reconciliation's snapshot never changes after a later in-period import; it surfaces separately and only `reopen()` incorporates it. | `test_a_late_import_after_completion_never_silently_alters_the_completed_result`; `test_reopen_clears_the_snapshot_and_a_later_completion_gets_a_fresh_one` |
| BNK-T050 | Existing | Completion is rejected while any in-period Bank Transaction lacks a confirmed Match, even at exact zero arithmetic difference. | `test_completion_is_rejected_while_any_in_period_transaction_is_unmatched`; `test_completion_succeeds_once_every_in_period_transaction_is_matched` |
| BNK-T051 | Existing | A Transfer Journal accepts exactly two confirmed Matches, one per leg; every other Journal type still accepts at most one. | `test_a_transfer_journal_accepts_two_matches_one_per_leg`; `test_a_non_transfer_journal_still_accepts_only_one_match`; `test_concurrent_confirmation_of_both_transfer_legs_both_succeed` |
| BNK-T052 | Existing | Opening a Reconciliation whose period overlaps any existing Reconciliation for the same Bank Account — in any lifecycle state — is rejected at both the application and schema boundary. | `test_opening_an_overlapping_period_is_rejected` (6-case matrix); `test_opening_an_adjacent_non_overlapping_period_is_allowed`; `test_opening_a_period_overlapping_a_completed_reconciliation_is_rejected`; `test_concurrent_opening_of_overlapping_periods_lets_only_one_succeed` |
| BNK-T054 | Existing | Every Match persists confidence as the discrete value `Exact`; a noncanonical value is rejected at the schema boundary. | `test_a_bank_transaction_is_suggested_against_a_matching_expense`; `test_confirming_a_suggested_match_persists_it`; `MatchesConfidenceMigrationTest`'s four migration proofs |
| BNK-T058 | Existing | Importing or reconciling a Bank Account/period with a negative balance fails closed with an explicit validation error. | `test_negative_balance_is_rejected`; `test_an_implied_overdraft_fails_closed_instead_of_computing_a_wrong_difference`; `test_opening_a_reconciliation_with_a_negative_balance_is_rejected_via_the_api` |
| BNK-T055 | Existing | Matching candidates cover every currently supported source type (Expense, Income, Transfer, Owner Equity) and independently reject wrong date, direction, Account, state, and Tenant. | `test_a_bank_transaction_is_suggested_against_a_matching_income`; `test_a_bank_transaction_is_suggested_against_a_matching_owner_equity_contribution`; `test_a_wrong_date_produces_no_candidate`; `test_a_wrong_direction_produces_no_candidate`; `test_a_wrong_bank_account_produces_no_candidate`; `test_a_draft_journal_produces_no_candidate`; `test_another_tenants_matching_journal_is_not_suggested` |

The new `reconciliation_completion_snapshots` table (`BNK-017`, `BNK-T049`) has its own dedicated migration test class (`ReconciliationCompletionSnapshotsTableMigrationTest`) proving foreign-key integrity, tenant isolation, uniqueness, and reversibility.

## 3. Required evidence not yet satisfied

| ID | State | Required proof | Invariant/reference |
| --- | --- | --- | --- |
| BNK-T053 | Deferred | Guided mapping of noncanonical statement formats. Out of this Draft's Active-version scope; does not gate Activation. | AETS-008 §12.5 |
| BNK-T057 | Required | A connected golden statement reconciles exactly to approved source evidence, Journals, Trial Balance, and reports. | AETS-012 Draft; cannot claim yet |

## 4. Activation gate

ATS-008 may become `Active` only when:

- AETS-008 is Active;
- every accepted `BNK-NNN` invariant, including `BNK-014`–`BNK-020`, maps to executable evidence;
- every §3 item marked `Required` is implemented and passing (items marked `Deferred` do not gate Activation);
- real PostgreSQL runs with zero skips/failures; and
- neither this document nor CI claims Proof of Accuracy before AETS-012's separate certification gate is satisfied.

## Changelog

- **0.5.0 (2026-09-16):** Moves `BNK-T055` to `Existing`: Income and Owner Equity Contribution candidates, and independent wrong-date/direction/Account/state/Tenant rejection, each with a dedicated test. Currency independence is not claimed — only MYR is supported anywhere in this system, so a currency-mismatch case is not meaningfully constructible. Only `BNK-T057` (blocked on AETS-012) and `BNK-T053` (deferred, out of scope) remain outstanding.
- **0.4.0 (2026-09-16):** Moves `BNK-T045`–`BNK-T054` and `BNK-T058` (nine of the ten `BNK-014`–`BNK-020` proof items) to `Existing` with real executable evidence, following AETS-008 v0.3.0's implementation of every §12 decision. `BNK-T053` stays `Deferred` (out of scope, §12.5); `BNK-T055` and `BNK-T057` remain `Required` — the former is broader pre-existing matching-coverage work this round didn't touch, the latter blocked on AETS-012.
- **0.3.0 (2026-09-16):** Follows AETS-008 v0.2.0's resolution of every §12 decision: retitles §3's required proofs against the now-decided `BNK-014`–`BNK-020` invariants instead of an unresolved policy, adds `BNK-T058` for the negative-balance fail-closed proof, and reclassifies `BNK-T053` (guided mapping) as `Deferred` since §12.5 places it out of this Draft's Active-version scope. No test in this document is newly `Existing`; all decided invariants remain unimplemented until their own proof lands.
- **0.2.2 (2026-09-16):** Moves BNK-T056 to Existing with authentication, request-validation, malformed/missing identifier, no-persistence, and cross-Tenant HTTP proofs covering the Banking route surface. Eleven broader or policy-dependent assurance items remain required.
- **0.2.1 (2026-09-16):** Moves BNK-T048 to Existing with a genuine two-process proof across every fixed lifecycle edge and reopening-history atomicity. Twelve policy-dependent or broader assurance items remain required.
- **0.2.0 (2026-09-15):** Adds BNK-T035–BNK-T044 for exact import counts, Money/currency, completion-time consistency, non-blank reopening reasons, and migration rollback. No Draft workflow decision changed.
- **0.1.1 (2026-09-15):** Maps the new Banking HTTP cross-Tenant fail-closed regression proof as BNK-T034; broader per-route HTTP assurance remains required. No Draft policy decision changed.
- **0.1.0 (2026-09-15):** Initial Draft inventory: 33 existing executable proofs and 13 required assurance items. No release or accuracy claim.
