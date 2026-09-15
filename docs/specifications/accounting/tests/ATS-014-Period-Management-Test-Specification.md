# ATS-014: Period Management Test Specification

- Status: Active
- Version: 1.0.0
- Effective date: 2026-09-13
- Owner: Accounting Core (see [`CODEOWNERS`](../../../../CODEOWNERS))
- Reviewers: Founder / Product Owner; CTO / Technical Partner; Accounting Domain Reviewer
- Related: [AETS-014](../AETS-014-Period-Management.md), [AETS-002](../AETS-002-Accounting-Invariants.md), [AETS-003](../AETS-003-Money-Specification.md), [AETS-004](../AETS-004-Journal-Posting-Model.md), [AETS-007](../AETS-007-Posting-Command.md), [AETS-009](../AETS-009-Financial-Reporting.md); [ADR-0004](../../../adr/0004-financial-integrity-principles.md), [ADR-0007](../../../adr/0007-money-representation-strategy.md)

## 1. Purpose

This normative specification proves AETS-014. Its 28 stable IDs map to concrete passing unit or real-PostgreSQL tests. It adds no Period behavior; it closes the formal traceability and assurance gap AETS-014 previously recorded.

## 2. Test strategy

- Pure closing-entry algebra is proved without persistence.
- Lifecycle, atomicity, tenant isolation, ledger/report effects, and correction behavior use real services and real PostgreSQL.
- Migration constraints use the production migration in an isolated PostgreSQL schema, never SQLite.
- Failure cases prove fail-closed behavior and, where material, absence of partial financial effects.

## 3. Traceability matrix

| Invariant | Test IDs |
| --- | --- |
| PER-001 | PER-T012–PER-T015, PER-T025–PER-T027 |
| PER-002 | PER-T011 |
| PER-003 | PER-T009, PER-T023, PER-T024 |
| PER-004 | PER-T019, PER-T021 |
| PER-005 | PER-T005, PER-T010 |
| PER-006 | PER-T016 |
| PER-007 | PER-T008, PER-T023 |
| PER-008 | PER-T006, PER-T017, PER-T027 |
| PER-009 | PER-T001–PER-T004, PER-T006, PER-T007 |

## 4. Test cases

### 4.1 Closing-entry algebra

| ID | Proof | Concrete test suffix (`test_…`) |
| --- | --- | --- |
| PER-T001 | Profit creates exact zeroing lines and a Credit Retained Earnings plug. | `a_profit_produces_a_credit_plug_on_retained_earnings` |
| PER-T002 | Loss creates an exact Debit Retained Earnings plug. | `a_loss_produces_a_debit_plug_on_retained_earnings` |
| PER-T003 | Exact break-even creates no plug line. | `an_exact_break_even_produces_no_plug_line` |
| PER-T004 | Revenue netting Debit is handled direction-agnostically. | `a_revenue_account_netting_debit_direction_is_handled_correctly` |
| PER-T005 | Zero Accounts are skipped and an all-zero close is rejected. | `zero_activity_accounts_are_skipped_and_nothing_to_close_is_rejected` |

These are methods of `PeriodClosingToPostingCommandTranslatorTest`.

### 4.2 Application and ledger integration

| ID | Proof | `PeriodClosingServiceIntegrationTest` suffix (`test_…`) |
| --- | --- | --- |
| PER-T006 | Profitable close zeroes Revenue/Expense, credits Retained Earnings, and preserves exact balanced reports. | `closing_a_profitable_period_zeroes_revenue_and_expense_and_balances_via_retained_earnings` |
| PER-T007 | Loss close debits Retained Earnings exactly. | `closing_a_period_with_a_loss_debits_retained_earnings` |
| PER-T008 | Same logical close/key replays one closure and Journal. | `closing_the_same_period_twice_with_the_same_key_replays` |
| PER-T009 | Backward close is rejected. | `closing_backwards_is_rejected` |
| PER-T010 | A period with nothing to close is rejected. | `nothing_to_close_is_rejected` |
| PER-T011 | Non-Equity Retained Earnings is rejected. | `a_non_equity_retained_earnings_account_is_rejected` |
| PER-T012 | Nonexistent Retained Earnings is rejected without a closing effect. | `a_missing_retained_earnings_account_is_rejected_before_any_closing_effect` |
| PER-T013 | Inactive Equity is rejected by ordinary posting validation. | `an_inactive_retained_earnings_account_is_rejected_by_the_ordinary_posting_validator` |
| PER-T014 | Non-posting-eligible Equity is rejected. | `a_non_posting_retained_earnings_account_is_rejected_by_the_ordinary_posting_validator` |
| PER-T015 | Another Tenant's Equity Account is unresolvable. | `another_tenants_retained_earnings_account_is_unresolvable` |
| PER-T016 | Closure-record failure rolls back Journal, lines, idempotency, Audit Event, and closure. | `a_period_closure_insert_failure_rolls_back_the_closing_journal_and_all_side_effects` |
| PER-T017 | Closing Journal uses ordinary reversal without mutating the original. | `a_closing_journal_uses_the_ordinary_reversal_path_without_mutating_the_original` |
| PER-T018 | Tenants with colliding visible values close independently. | `period_closing_is_isolated_between_tenants_with_colliding_visible_values` |
| PER-T019 | Posting inside a closed period is rejected. | `an_ordinary_posting_into_a_closed_period_is_rejected` |
| PER-T020 | Balance Sheet shows Retained Earnings plus only new unclosed activity, without double counting. | `the_unclosed_books_convention_correctly_shows_only_post_closing_activity` |
| PER-T021 | Posting after the watermark is accepted. | `a_posting_dated_after_the_closed_period_is_accepted` |

### 4.3 Production migration

| ID | Proof | `PeriodClosuresTableMigrationTest` suffix (`test_…`) |
| --- | --- | --- |
| PER-T022 | Migration applies, exposes required columns, reverses, and reapplies. | `migration_applies_and_reverses_cleanly` |
| PER-T023 | Primary key rejects a duplicate Tenant/date. | `same_tenant_cannot_close_the_same_date_twice` |
| PER-T024 | Same date is independent between Tenants. | `same_closed_date_is_independent_between_tenants` |
| PER-T025 | Closure cannot reference a nonexistent Journal. | `closure_cannot_reference_a_nonexistent_journal` |
| PER-T026 | Composite foreign key rejects another Tenant's Journal. | `closure_cannot_reference_another_tenants_journal` |
| PER-T027 | Referenced closing Journal cannot be deleted. | `a_referenced_closing_journal_cannot_be_deleted` |
| PER-T028 | Table has no mutable or extraneous columns. | `table_has_no_mutable_or_extraneous_columns` |

## 5. Deferred scope

Reopening, discrete Periods/fiscal calendars, automatic closing, and multi-currency remain excluded exactly as AETS-014 §2.2 states. No test implies those capabilities exist.

## 6. Changelog

- **1.0.0 (2026-09-13):** Initial Active specification mapping 28 concrete tests to all nine AETS-014 invariants, including newly closed atomicity, Account-validity, tenant-isolation, correction-path, and migration assurance gaps.
