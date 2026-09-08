<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every Accounting/Transactions/Banking integration test class shares
 * one real, persistent PostgreSQL database across the whole PHPUnit
 * process (never SQLite, per this codebase's own established "real
 * Postgres always" convention — see any integration test's own
 * docblock). A test class that needs to freely delete `accounts`/
 * `journals` rows in its own `setUp()` must first clear every table
 * that could hold a foreign key onto them, in dependency order, or
 * risk a foreign-key violation caused by a *completely unrelated* test
 * class's own leftover fixture rows — a fixture id like `account-cash`
 * or `bank-account-0001` is reused verbatim across dozens of test
 * files, and PHPUnit's test *class* execution order is not
 * alphabetical, file-path-based, or otherwise stable (confirmed
 * directly: `--order-by=random` surfaces cross-class collisions a
 * default run does not).
 *
 * **One canonical list, not twenty-five hand-rolled ones.** Before this
 * trait existed, each integration test class maintained its own
 * partial copy of "every table that might reference `accounts`/
 * `journals`" — every time a new such table was added (M13's
 * `period_closures`, M14's `transfers`, M17's `bank_accounts`/
 * `bank_transactions`/`bank_statement_import_batches`, M18's
 * `matches`/`reconciliations`/`reconciliation_reopenings`, M20's
 * `invoices`/`invoice_lines`, ADR-0009's `tasks`/`proposals`/
 * `task_transitions`), *every*
 * existing file needed the identical edit repeated, and a missed file
 * silently reintroduced the exact same class of bug. This trait
 * exists specifically so that the next such table only ever needs to
 * be added in one place.
 *
 * Use {@see cleanSharedAccountingTables()} in `setUp()` (and, for
 * classes that share this database across an entire suite run rather
 * than per-test, in `tearDown()` too) instead of a class-local
 * `TABLES_TO_CLEAN` constant or an inline `foreach`.
 */
trait CleansSharedAccountingTables
{
    /**
     * The definitive dependency order for every shared table this
     * codebase currently has that can hold a foreign key (directly or
     * transitively) onto `accounts` or `journals` — dependents listed
     * before whatever they depend on, so a straight top-to-bottom
     * `DELETE` never hits a foreign-key violation regardless of which
     * of these tables happen to exist or hold rows at the time.
     *
     * @var list<string>
     */
    private static array $sharedAccountingTablesInDependencyOrder = [
        'payment_allocations',
        'payments',
        'reconciliation_reopenings',
        'matches',
        'bank_transactions',
        'reconciliations',
        'bank_statement_import_batches',
        'bank_accounts',
        'invoice_lines',
        'invoices',
        'period_closures',
        'posting_idempotency_keys',
        'posting_source_fingerprints',
        'audit_events',
        'journal_evidence_links',
        'expenses',
        'incomes',
        'transfers',
        'owner_equity_transactions',
        'task_transitions',
        'proposals',
        'tasks',
        'journal_lines',
        'journals',
        'accounts',
    ];

    private static function cleanSharedAccountingTables(): void
    {
        foreach (self::$sharedAccountingTablesInDependencyOrder as $table) {
            if (Schema::connection('pgsql')->hasTable($table)) {
                DB::connection('pgsql')->table($table)->delete();
            }
        }
    }
}
