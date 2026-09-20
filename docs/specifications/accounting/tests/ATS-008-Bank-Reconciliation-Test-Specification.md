# ATS-008: Bank Import, Matching & Reconciliation Test Specification

- Status: Draft
- Version: 0.9.2
- Effective date: Not effective — pending AETS-008 activation
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-008](../AETS-008-Bank-Reconciliation.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0007](../../../adr/0007-money-representation-strategy.md)

## 1. Purpose and status warning

This Draft inventories current Banking proof and names missing acceptance evidence. It is not a release certificate. IDs marked `Existing` map to executable tests already in the repository; IDs marked `Required` must be implemented against the decisions AETS-008 §12 now records (as of v0.2.0), not invented independently; IDs marked `Deferred` test a capability §12 has explicitly placed out of this Draft's Active-version scope and do not gate Activation.

All integration and schema tests require real PostgreSQL and must run with skip/warning/risky/deprecation failures enabled.

## 2. Traceability matrix

Every `BNK-NNN` candidate invariant AETS-008 §9 names, mapped to the `BNK-TNNN` evidence rows in §3 that prove it. A row with no Test IDs is an honest gap, not an oversight — see its own note.

| Invariant | Test IDs | Note |
| --- | --- | --- |
| `BNK-001` — Import has zero ledger effect | BNK-T059 | |
| `BNK-002` — Money and bank direction are exact and unambiguous | BNK-T001, BNK-T038, BNK-T039, BNK-T061, BNK-T066, BNK-T068 | Canonical direction (`MoneyIn`/`MoneyOut`) is additionally proven by the Bank Transaction migration test class's own CHECK-constraint and valid-insert proofs (§3.1's closing note), not a separate `BNK-TNNN` ID. `BNK-T061` additionally proves this holds when a third format (Maybank PDF) must first translate its own amount-plus-sign encoding into the fixed schema's separate fields. `BNK-T066` proves it holds unchanged when that same PDF is also empty-user-password encrypted, the overwhelmingly common real-world case. `BNK-T068` proves it holds when the PDF's own text extraction omits whitespace at a column boundary entirely, another real-world variant. |
| `BNK-003` — A malformed statement has zero persistent effect | BNK-T004, BNK-T063, BNK-T064, BNK-T067 | `BNK-T063`/`BNK-T064` extend this to Maybank PDF's own failure modes (a totals-mismatch cross-check, an unrecognized line) that CSV/XLSX have no equivalent of. `BNK-T067` extends it to a PDF locked with a genuine password `qpdf` cannot guess. |
| `BNK-004` — Import Batch and inserted Bank Transactions commit atomically | BNK-T004, BNK-T007 | |
| `BNK-005` — File replay and overlapping-row import cannot create duplicate Bank Transactions | BNK-T002, BNK-T003, BNK-T045, BNK-T046 | Single-process (`T002`/`T003`) and genuine concurrent (`T045`/`T046`) cases both covered. |
| `BNK-006` — Every Banking relationship and operation is tenant-isolated | BNK-T005, BNK-T006, BNK-T026–BNK-T034, BNK-T056 | §3.4's entire section is this invariant's own dedicated evidence. |
| `BNK-007` — Candidate generation is read-only and confirmation revalidates current eligibility | BNK-T010, BNK-T011, BNK-T012, BNK-T047 | |
| `BNK-008` — A Bank Transaction has at most one immutable confirmed Match | BNK-T009, BNK-T011, BNK-T047, BNK-T051 | `BNK-T051` proves the sole exception (`BNK-014`) is bounded, not a silent hole in this invariant. |
| `BNK-009` — Difference arithmetic is exact over the inclusive period | BNK-T016, BNK-T017, BNK-T018 | |
| `BNK-010` — `Balanced` and `Completed` require exact zero live difference | BNK-T019, BNK-T020, BNK-T021 | |
| `BNK-011` — Lifecycle transitions are serialized and atomic | BNK-T025, BNK-T048 | |
| `BNK-012` — Reopening is explicit, reasoned, append-only audited, and atomic | BNK-T022, BNK-T023, BNK-T024, BNK-T025, BNK-T043 | |
| `BNK-013` — AI/matching cannot post or bypass Accounting Core | BNK-T060 | True by construction (`App\Domain\Banking\MatchingService` has no posting-service dependency) **and** now behaviorally proven. |
| `BNK-014` — Transfer Journals accept exactly two confirmed Matches, one per leg; every other type at most one | BNK-T051 | |
| `BNK-015` — A Reconciliation's period never overlaps another for the same Bank Account, in any lifecycle state | BNK-T052 | |
| `BNK-016` — Completion requires exact zero difference **and** a confirmed Match for every in-period Bank Transaction | BNK-T019, BNK-T020, BNK-T021, BNK-T050 | |
| `BNK-017` — A Completed Reconciliation's snapshot is immutable; only `reopen()` incorporates a later import | BNK-T049 | The dedicated `ReconciliationCompletionSnapshotsTableMigrationTest` (§3's closing note) additionally proves the snapshot table's own FK integrity, tenant isolation, and reversibility. |
| `BNK-018` — Concurrent import/match-confirmation races resolve as deterministic replay or an explicit typed conflict | BNK-T045, BNK-T046, BNK-T047, BNK-T048, BNK-T052 | |
| `BNK-019` — Every Match's confidence is the discrete value `Exact` | BNK-T054 | |
| `BNK-020` — Import/reconciliation of a negative balance fails closed | BNK-T058 | |

Every `BNK-NNN` invariant in this matrix now maps to at least one `BNK-TNNN` executable proof. `BNK-T057` (§4) and Accounting Domain Reviewer sign-off remain the only Activation blockers not resolved by this matrix.

## 3. Current executable evidence

### 3.1 Import

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T001 | Existing | Well-formed file imports exact rows and counts. | `a_well_formed_statement_is_imported_and_normalized_exactly` |
| BNK-T002 | Existing | Byte-identical replay returns the original batch. | `reuploading_the_byte_identical_file_replays_instead_of_duplicating` |
| BNK-T003 | Existing | Overlapping re-export skips duplicate natural-key rows. | `an_overlapping_non_identical_reexport_skips_only_the_duplicate_rows` |
| BNK-T004 | Existing | Malformed file is atomic with zero rows/batches. | `a_malformed_statement_is_rejected_and_persists_nothing` |
| BNK-T005 | Existing | Identical input is independent between Tenants. | `two_tenants_importing_do_not_interfere` |
| BNK-T006 | Existing | Identical input is independent between Bank Accounts. | `two_bank_accounts_with_identical_file_content_do_not_interfere` |
| BNK-T007 | Existing | Forced transaction-row failure rolls back the whole import. | `a_forced_bank_transaction_insert_failure_rolls_back_the_entire_import` |
| BNK-T059 | Existing | A fresh import and a replay both leave the `journals` table's row count for the Tenant exactly unchanged. | `test_import_leaves_the_journals_table_unchanged_for_a_fresh_import_and_a_replay` |
| BNK-T061 | Existing | Maybank PDF: a well-formed statement with multi-line narrative continuation parses into the identical fixed schema, translating the amount-plus-trailing-sign encoding into separate amount/direction fields. | `test_parses_a_well_formed_statement_with_narrative_continuation` |
| BNK-T062 | Existing | Maybank PDF: a transaction's narrative continuation lines spanning a page break are still attached to the correct row. | `test_a_transactions_continuation_spans_a_page_break` |
| BNK-T063 | Existing | Maybank PDF: a statement whose own declared `ENDING BALANCE`/`TOTAL CREDIT`/`TOTAL DEBIT` do not reconcile with what was actually parsed is rejected in full. | `test_a_totals_mismatch_is_rejected` |
| BNK-T064 | Existing | Maybank PDF: an unrecognized line (neither a transaction row, a continuation line, nor a known structural anchor) is rejected rather than silently skipped or misclassified. | `test_an_unrecognized_line_before_any_transaction_row_is_rejected`; `test_a_pdf_with_no_transaction_table_at_all_is_rejected` |
| BNK-T065 | Existing | Maybank PDF: a real HTTP upload through `BankStatementImportController` resolves `.pdf` to `MaybankPdfBankStatementParser` and persists real Bank Transactions, proven against real PostgreSQL and (separately) a real browser. | `test_registering_a_bank_account_and_importing_a_maybank_pdf_statement_end_to_end` (API); `importing a Maybank PDF bank statement records real transactions` (E2E, `xlsx-and-pdf-export.spec.ts`) |
| BNK-T066 | Existing | Maybank PDF: a statement encrypted with an empty user password (the overwhelmingly common real-world case for bank-issued statement PDFs) still parses, identically to its unencrypted original — `smalot/pdfparser` has no decryption support of its own; `QpdfDecryptor` strips this transparently first. | `test_a_statement_encrypted_with_an_empty_user_password_still_parses` |
| BNK-T067 | Existing | Maybank PDF: a statement locked with a genuine, non-empty password is rejected with actionable guidance (distinct from the prior opaque "Secured pdf file are currently not supported" error) — `qpdf` cannot guess a password it was never given. | `test_a_statement_locked_with_a_real_password_is_rejected_with_actionable_guidance` |
| BNK-T068 | Existing | Maybank PDF: a transaction line with no whitespace at the date-description or sign-balance boundary (the exact shape a real statement's own text extraction produced) still parses correctly. | `test_a_transaction_line_with_no_whitespace_at_column_boundaries_still_parses` |

### 3.2 Matching

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T008 | Existing | Exact eligible Expense is suggested. | `a_bank_transaction_is_suggested_against_a_matching_expense` |
| BNK-T009 | Existing | Human confirmation persists one immutable Match. | `confirming_a_suggested_match_persists_it` |
| BNK-T010 | Existing | Confirmed Bank Transaction is not suggested again. | `a_confirmed_match_is_never_suggested_again` |
| BNK-T011 | Existing | Reconfirming an already-matched Bank Transaction is rejected. | `confirming_an_already_matched_bank_transaction_is_rejected` |
| BNK-T012 | Existing | A noncandidate Journal is rejected at confirmation. | `confirming_a_journal_that_is_not_a_valid_candidate_is_rejected` |
| BNK-T013 | Existing | Amount mismatch produces no candidate. | `amount_mismatch_produces_no_candidate` |
| BNK-T060 | Existing | `suggestFor()` and `confirm()` create no Journal — the `journals` table's row count for the Tenant is exactly unchanged across both calls. | `test_matching_creates_no_journal` |

### 3.3 Reconciliation lifecycle

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

### 3.4 Schema tenant isolation

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

### 3.5 Exact values and lifecycle persistence

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

### 3.6 Lifecycle concurrency

| ID | State | Proof | Executable suffix (`test_…`) |
| --- | --- | --- | --- |
| BNK-T048 | Existing | Genuine two-process races across every lifecycle edge permit exactly one transition, preserve the final state, and append exactly one reopening-history row. | `concurrent_lifecycle_transitions_cannot_overwrite_or_skip_state` |
| BNK-T056 | Existing | Every Banking route requires authentication; every write boundary rejects invalid payloads without persistence; malformed identifiers fail as validation errors and canonical missing identifiers fail closed. | `every_banking_route_rejects_unauthenticated_access`; `banking_write_routes_reject_invalid_payloads_without_persistence`; `banking_routes_fail_deterministically_for_malformed_and_missing_identifiers` |

The existing Bank Account and Bank Transaction migration test classes additionally prove table shape, canonical direction, file-hash/natural-key uniqueness, basic foreign keys, exact valid inserts, and their original migration rollback paths.

### 3.7 Policy-resolved assurance (2026-09-16, `BNK-014`–`BNK-020`)

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

## 4. Required evidence not yet satisfied

| ID | State | Required proof | Invariant/reference |
| --- | --- | --- | --- |
| BNK-T053 | Deferred | Guided mapping of noncanonical statement formats. Out of this Draft's Active-version scope; does not gate Activation. | AETS-008 §12.5 |
| BNK-T057 | Required (implemented against a candidate dataset) | A connected golden statement reconciles exactly to approved source evidence, Journals, Trial Balance, and reports. As of 2026-09-19, this is implemented and passing against `hore-my-poa-v1` v1.0.0 — a real bank statement import → matching → completed Reconciliation at exact RM0.00 difference, reconciling exactly to Trial Balance/P&L/Balance Sheet/GL/Evidence Index/Aging (`tests/Feature/ProofOfAccuracy/ProofOfAccuracyCertificationTest.php`; AETS-012 §10, ATS-012 §5.2). Stays `Required`, not `Existing`, because the dataset is a CTO proposal, not yet Accounting Domain Reviewer-approved — this row cannot claim "approved source evidence" until that review lands. | AETS-012 Draft; candidate proof exists, approval outstanding |

## 5. Activation gate

ATS-008 may become `Active` only when:

- AETS-008 is Active;
- every accepted `BNK-NNN` invariant, including `BNK-014`–`BNK-020`, maps to executable evidence (§2 — satisfied as of v0.8.0);
- every §4 item marked `Required` is implemented and passing (items marked `Deferred` do not gate Activation);
- real PostgreSQL runs with zero skips/failures; and
- neither this document nor CI claims Proof of Accuracy before AETS-012's separate certification gate is satisfied.

## Changelog

- **0.9.2 (2026-09-20):** Companion update to AETS-008 v0.9.2's second real-usage bug fix — adds `BNK-T068` (a transaction line with no whitespace at the date-description or sign-balance boundary, the exact shape a real statement's own text extraction produced, still parses). Extends the `BNK-002` traceability row (§2). No existing test ID's prior coverage changed.
- **0.9.1 (2026-09-20):** Companion update to AETS-008 v0.9.1's real-usage bug fix — adds `BNK-T066` (a statement encrypted with an empty user password still parses, via the new `QpdfDecryptor`) and `BNK-T067` (a statement locked with a genuine password is rejected with actionable guidance). Extends the `BNK-002` and `BNK-003` traceability rows (§2) accordingly. No existing test ID's prior coverage changed.
- **0.9.0 (2026-09-19):** Adds `BNK-T061`–`BNK-T065` for Maybank PDF import (AETS-008 §12.10/§5.2): well-formed parse with narrative continuation, continuation spanning a page break, the totals-mismatch fail-closed check, unrecognized-line fail-closed, and a real HTTP/browser end-to-end proof. §2's traceability matrix updated for `BNK-002`/`BNK-003`. No other row changed.
- **0.8.0 (2026-09-19):** Closes both gaps §2's traceability matrix found: `BNK-T059` (`journals` unchanged across a fresh import and a replay) and `BNK-T060` (`suggestFor()`/`confirm()` create no Journal) are now `Existing` with real executable tests, moved from §4 into §3.1/§3.2. Every accepted `BNK-NNN` invariant now maps to at least one `BNK-TNNN`. `BNK-T057` and Accounting Domain Reviewer sign-off remain the only Activation blockers.
- **0.7.0 (2026-09-19):** Adds §2, the formal Traceability matrix AETS-008 §13's activation checklist named as outstanding ("ATS-008 updated from evidence inventory to complete normative traceability") — every `BNK-001`–`BNK-020` candidate invariant mapped to its `BNK-TNNN` evidence rows. Finds two genuine gaps in the process, `BNK-001` and `BNK-013`, neither previously named as required evidence; adds `BNK-T059`/`BNK-T060` to §4 to track them and updates §5's Activation gate accordingly. Renumbers former §2–§4 to §3–§5; no existing evidence row's State or Proof text changed.
- **0.6.0 (2026-09-19):** Updates `BNK-T057`: now implemented and passing against AETS-012's `hore-my-poa-v1` v1.0.0 candidate dataset, closing the implementation gap named "blocked on AETS-012." Stays `Required` (not `Existing`) since the dataset is not yet Accounting Domain Reviewer-approved. No other row changed.
- **0.5.0 (2026-09-16):** Moves `BNK-T055` to `Existing`: Income and Owner Equity Contribution candidates, and independent wrong-date/direction/Account/state/Tenant rejection, each with a dedicated test. Currency independence is not claimed — only MYR is supported anywhere in this system, so a currency-mismatch case is not meaningfully constructible. Only `BNK-T057` (blocked on AETS-012) and `BNK-T053` (deferred, out of scope) remain outstanding.
- **0.4.0 (2026-09-16):** Moves `BNK-T045`–`BNK-T054` and `BNK-T058` (nine of the ten `BNK-014`–`BNK-020` proof items) to `Existing` with real executable evidence, following AETS-008 v0.3.0's implementation of every §12 decision. `BNK-T053` stays `Deferred` (out of scope, §12.5); `BNK-T055` and `BNK-T057` remain `Required` — the former is broader pre-existing matching-coverage work this round didn't touch, the latter blocked on AETS-012.
- **0.3.0 (2026-09-16):** Follows AETS-008 v0.2.0's resolution of every §12 decision: retitles §3's required proofs against the now-decided `BNK-014`–`BNK-020` invariants instead of an unresolved policy, adds `BNK-T058` for the negative-balance fail-closed proof, and reclassifies `BNK-T053` (guided mapping) as `Deferred` since §12.5 places it out of this Draft's Active-version scope. No test in this document is newly `Existing`; all decided invariants remain unimplemented until their own proof lands.
- **0.2.2 (2026-09-16):** Moves BNK-T056 to Existing with authentication, request-validation, malformed/missing identifier, no-persistence, and cross-Tenant HTTP proofs covering the Banking route surface. Eleven broader or policy-dependent assurance items remain required.
- **0.2.1 (2026-09-16):** Moves BNK-T048 to Existing with a genuine two-process proof across every fixed lifecycle edge and reopening-history atomicity. Twelve policy-dependent or broader assurance items remain required.
- **0.2.0 (2026-09-15):** Adds BNK-T035–BNK-T044 for exact import counts, Money/currency, completion-time consistency, non-blank reopening reasons, and migration rollback. No Draft workflow decision changed.
- **0.1.1 (2026-09-15):** Maps the new Banking HTTP cross-Tenant fail-closed regression proof as BNK-T034; broader per-route HTTP assurance remains required. No Draft policy decision changed.
- **0.1.0 (2026-09-15):** Initial Draft inventory: 33 existing executable proofs and 13 required assurance items. No release or accuracy claim.
