<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Schema;

/**
 * A distinct, complementary companion to
 * {@see CleansSharedAccountingTables}. That trait clears *rows*
 * (`DELETE FROM`) between tests that share one long-lived database;
 * this trait drops the specific *tables* ADR-0009/WTS-001 introduced
 * (`task_transitions`, `proposals`, `task_drafts`, `tasks`) for the
 * small set of test classes that test migration reversibility itself
 * by unconditionally tearing down and rebuilding
 * `journals`/`journal_lines`/`accounts` — and PostgreSQL refuses to
 * drop either while `tasks.result_journal_id` or `proposals`'
 * composite Account foreign keys still reference them.
 *
 * **Deliberately narrow.** This trait does not attempt to also cover
 * `invoices`/`payments`/`bank_accounts`/`period_closures`/etc. — several
 * consuming files intentionally rely on those tables already existing
 * from an earlier-run test class in the same suite process and never
 * recreate them themselves; a broader unconditional drop here already
 * proved to break at least one such file
 * (`ReportingQueriesIntegrationTest`, `period_closures`) during this
 * trait's own development. Each consuming file keeps managing its own,
 * pre-existing set of dependent tables exactly as it already did;
 * this trait only adds the three tables ADR-0009 is responsible for.
 *
 * Use {@see dropTablesDependentOnJournalsAndAccounts()} immediately
 * before a test's own `Schema::dropIfExists('journal_lines')` /
 * `dropIfExists('journals')` / `dropIfExists('accounts')` calls.
 */
trait DropsTablesDependentOnJournalsAndAccounts
{
    /**
     * The tables ADR-0009 added that hold a foreign key (directly or
     * transitively) onto `journals` or `accounts`, listed dependents-
     * before-dependencies. Deliberately does not include `journal_lines`,
     * `journals`, or `accounts` themselves — the caller drops those
     * afterward, on its own schedule.
     *
     * @var list<string>
     */
    private static array $tablesDependentOnJournalsAndAccounts = [
        'task_transitions',
        'proposals',
        'task_drafts',
        'tasks',
    ];

    private static function dropTablesDependentOnJournalsAndAccounts(): void
    {
        foreach (self::$tablesDependentOnJournalsAndAccounts as $table) {
            Schema::connection('pgsql')->dropIfExists($table);
        }
    }
}
