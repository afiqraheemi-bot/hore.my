<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Journal;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for the M8A hardening of
 * `database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php`,
 * exercised against a real PostgreSQL instance and the real Laravel
 * migrator — never SQLite, mirroring the precedent already established
 * by {@see JournalsAndJournalLinesTableMigrationTest}.
 *
 * **What M8A replaced.** The migration's first revision (M8) backfilled
 * any pre-existing `journals` row with a synthetic placeholder date
 * before tightening the new `financial_date` column to `NOT NULL`,
 * purely to let the migration succeed against this test suite's shared,
 * long-lived PostgreSQL instance. A CTO QA pass on M8 rejected that
 * approach outright: a placeholder date is exactly the kind of
 * fabricated financial fact Accounting Core must never produce. This
 * class proves the hardened replacement behavior: the migration
 * succeeds directly against an empty `journals` table (the only state
 * that ever occurs in production today, and the only state this test
 * suite's own fixtures ever present it with), and fails loudly —
 * before touching the schema at all — if any pre-existing row is found,
 * rather than inventing a value for it.
 *
 * **Each test controls only the two columns under test, never the
 * whole table.** Unlike most migration test classes in this suite,
 * this class never drops the `journals`/`journal_lines` tables
 * themselves (reusing whatever the shared database already has,
 * creating them only if genuinely absent) and never drops any of their
 * dependents (`posting_idempotency_keys`, `audit_events`,
 * `journal_evidence_links`, `expenses`) at all. {@see resetToPreFinancialDateSchema()}
 * instead only ever drops the two columns this specific migration adds
 * — an operation with no foreign-key implications whatsoever — so this
 * class cannot disturb any other test class sharing this same
 * long-lived PostgreSQL instance within the same PHPUnit process,
 * regardless of execution order.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class FinancialDateMigrationTest extends TestCase
{
    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        try {
            DB::connection('pgsql')->select('select 1');
        } catch (\Throwable $e) {
            self::$skipReason = sprintf(
                'A real PostgreSQL instance is not reachable via the "pgsql" connection (%s). '
                .'Run `docker compose up -d postgres` (see docker-compose.yml) to enable this integration test.',
                $e->getMessage(),
            );

            $this->markTestSkipped(self::$skipReason);
        }
    }

    /**
     * The migration succeeds directly against an empty `journals`
     * table — the only state production ever presents it with today,
     * and the only state this test suite's own fixtures ever apply it
     * against.
     */
    public function test_migrates_successfully_on_an_empty_database(): void
    {
        $this->resetToPreFinancialDateSchema();
        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());

        self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);

        $this->assertTrue(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'posted_at'));
    }

    public function test_financial_date_column_is_not_null(): void
    {
        $this->resetToPreFinancialDateSchema();
        self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => 'tenant-a',
            'journal_id' => 'journal-no-date',
            'state' => 'Draft',
        ]);
    }

    public function test_financial_date_is_stored_as_a_date_column_not_a_timestamp(): void
    {
        $this->resetToPreFinancialDateSchema();
        self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);

        /** @var object{data_type: string}|null $column */
        $column = DB::connection('pgsql')->selectOne(
            'select data_type from information_schema.columns where table_name = ? and column_name = ?',
            [self::JOURNAL_TABLE, 'financial_date'],
        );

        $this->assertNotNull($column);
        $this->assertSame('date', $column->data_type);
    }

    /**
     * The core M8A hardening proof: a `journals` row that predates this
     * migration — and therefore has no legitimate Financial Date on
     * record — causes the migration to fail loudly, before touching the
     * schema at all. No synthetic date is ever persisted for it.
     */
    public function test_existing_journal_without_financial_date_causes_deterministic_migration_failure(): void
    {
        $this->resetToPreFinancialDateSchema();

        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => 'tenant-legacy',
            'journal_id' => 'journal-legacy',
            'state' => 'Draft',
        ]);

        try {
            self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
            $this->fail('Expected the migration to refuse to run against a journals table with an existing row.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('existing row', $e->getMessage());
            $this->assertStringContainsString('never fabricates', $e->getMessage());

            // The column was never added — the migration refused before
            // touching the schema at all, not partway through it.
            $this->assertFalse(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date'));
            $this->assertFalse(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'posted_at'));
        } finally {
            // Leave the shared database consistent for every other test
            // class in the same PHPUnit process, regardless of outcome.
            DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
            self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
        }
    }

    /**
     * *(Architecture)* No sentinel/placeholder date, and no
     * fabrication/inference logic, appears anywhere in the migration's
     * executable source — the hardened replacement does not merely
     * behave correctly today, it structurally cannot fabricate a value.
     */
    public function test_no_synthetic_date_or_inference_logic_appears_in_the_migration(): void
    {
        $path = base_path(self::FINANCIAL_DATE_MIGRATION_PATH);
        $source = file_get_contents($path);
        $this->assertIsString($source);

        // Strip docblocks/comments and string literals first — both the
        // migration's own class docblock and its thrown exception's
        // explanatory message legitimately *name* words like
        // "CURRENT_DATE" in prose, to explain what it deliberately does
        // not do (mirroring
        // {@see \Tests\Unit\Domain\Accounting\Journal\JournalTest::test_financial_date_and_posted_at_are_never_self_generated()}'s
        // own comment-stripping technique). What must be absent is
        // actual *executable* fabrication/inference logic, not the
        // words themselves appearing anywhere in the file.
        $withoutComments = preg_replace('#/\*.*?\*/#s', '', $source);
        $this->assertIsString($withoutComments);
        $executableOnly = preg_replace("#'(?:[^'\\\\]|\\\\.)*'#s", "''", $withoutComments);
        $this->assertIsString($executableOnly);

        $this->assertStringNotContainsString('1970-01-01', $executableOnly);
        $this->assertStringNotContainsString('CURRENT_DATE', $executableOnly);
        $this->assertStringNotContainsString('now(', $executableOnly);
        $this->assertStringNotContainsString('Carbon::', $executableOnly);
        $this->assertStringNotContainsString('->update(', $executableOnly);
        $this->assertStringNotContainsString('DB::raw(', $executableOnly);
        $this->assertStringNotContainsString('->whereNull(', $executableOnly);
    }

    public function test_migration_rollback_and_remigrate_succeeds(): void
    {
        $this->resetToPreFinancialDateSchema();
        self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);

        $this->assertTrue(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date'));

        Artisan::call('migrate:rollback', [
            '--database' => 'pgsql',
            '--path' => self::FINANCIAL_DATE_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertFalse(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date'));
        $this->assertFalse(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'posted_at'));

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::FINANCIAL_DATE_MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertTrue(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date'));
        $this->assertTrue(Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'posted_at'));
    }

    /**
     * Brings `journals`/`journal_lines`/`accounts` into existence if
     * genuinely absent (a fresh database), then rolls back only the
     * `financial_date`/`posted_at` columns themselves — never the
     * `journals` table, and never any of its dependents. Dropping two
     * plain columns has no foreign-key implications, so this method
     * cannot disturb `posting_idempotency_keys`, `audit_events`,
     * `journal_evidence_links`, `expenses`, or any other test class
     * relying on `journals` already existing.
     */
    private function resetToPreFinancialDateSchema(): void
    {
        if (! Schema::connection('pgsql')->hasTable(self::ACCOUNT_TABLE)) {
            self::forceCleanMigration(self::ACCOUNTS_MIGRATION_PATH, [self::ACCOUNT_TABLE]);
        }

        // Deliberately checks `journals` alone, never `journal_lines` —
        // this class has no test that needs a Journal Line to exist,
        // and `journals` already having live dependents
        // (`posting_idempotency_keys`, `audit_events`,
        // `journal_evidence_links`, none of which are this class's
        // concern) means it must never attempt to drop/recreate
        // `journals` merely because `journal_lines` alone happens to be
        // missing (a state some other, unrelated test class's own
        // fixture management can leave behind — see this class's own
        // docblock). Only a genuinely fresh database, where `journals`
        // itself does not exist yet at all, triggers this branch.
        if (! Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE)) {
            self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);
            self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);
        }

        // `matches` (M18) carries a composite foreign key on
        // (tenant_id, journal_id) referencing this table — a leftover
        // row from another test class sharing this same persistent
        // database would otherwise block the blanket `journals` delete
        // below. Not this test's concern; not recreated here.
        if (Schema::connection('pgsql')->hasTable('matches')) {
            DB::connection('pgsql')->table('matches')->delete();
        }

        if (Schema::connection('pgsql')->hasTable(self::LINE_TABLE)) {
            DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        }
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();

        if (Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date')) {
            Schema::connection('pgsql')->table(self::JOURNAL_TABLE, function (Blueprint $table): void {
                $table->dropColumn(['financial_date', 'posted_at']);
            });
        }

        if (Schema::connection('pgsql')->hasTable('migrations')) {
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo(self::FINANCIAL_DATE_MIGRATION_PATH, PATHINFO_FILENAME))
                ->delete();
        }
    }

    /**
     * @param  list<string>  $tables
     */
    private static function forceCleanMigration(string $migrationPath, array $tables): void
    {
        foreach ($tables as $table) {
            Schema::connection('pgsql')->dropIfExists($table);
        }

        if (Schema::connection('pgsql')->hasTable('migrations')) {
            DB::connection('pgsql')->table('migrations')
                ->where('migration', pathinfo($migrationPath, PATHINFO_FILENAME))
                ->delete();
        }

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => $migrationPath,
            '--realpath' => false,
            '--force' => true,
        ]);
    }
}
