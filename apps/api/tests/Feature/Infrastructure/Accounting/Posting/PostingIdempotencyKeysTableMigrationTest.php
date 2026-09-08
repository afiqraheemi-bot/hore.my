<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Posting;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\DropsTablesDependentOnJournalsAndAccounts;
use Tests\Feature\Infrastructure\Accounting\Journal\JournalsAndJournalLinesTableMigrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for the production `posting_idempotency_keys`
 * table migration (M4-T15,
 * `database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php`),
 * exercised against a real PostgreSQL instance and the real Laravel
 * migrator — never a hand-copied re-implementation of the schema, and
 * never SQLite as evidence of PostgreSQL-specific constraint behavior
 * (composite primary key, composite foreign key), mirroring the
 * precedent already established for
 * {@see JournalsAndJournalLinesTableMigrationTest}.
 *
 * This is schema-only coverage. No lookup, replay, recording service,
 * or concurrency orchestration exists yet (deferred to M4-T16 and
 * later) — this class proves only the database primitives that future
 * work will rely on: the composite uniqueness constraint, the
 * tenant-safe Journal reference, and the column-level `IdempotencyKey`
 * length compatibility. It does not claim concurrent replay/duplicate
 * protection — only that the unique constraint the mechanism will
 * eventually rely on actually exists and actually rejects a duplicate
 * insert.
 *
 * `journals` and `accounts` are real dependencies of this table's own
 * composite foreign key, so this class also ensures both existing
 * production migrations are applied first.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingIdempotencyKeysTableMigrationTest extends TestCase
{
    use DropsTablesDependentOnJournalsAndAccounts;

    private const TABLE = 'posting_idempotency_keys';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const MIGRATION_PATH = 'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        DB::connection('pgsql')->table(self::TABLE)->delete();
        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        if (Schema::connection('pgsql')->hasTable('payments')) {
            DB::connection('pgsql')->table('payment_allocations')->delete();
            DB::connection('pgsql')->table('payments')->delete();
        }
        if (Schema::connection('pgsql')->hasTable('invoices')) {
            DB::connection('pgsql')->table('invoice_lines')->delete();
            DB::connection('pgsql')->table('invoices')->delete();
        }
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        foreach (['reconciliation_reopenings', 'matches', 'bank_transactions', 'reconciliations', 'bank_statement_import_batches', 'bank_accounts'] as $bankingTable) {
            if (Schema::connection('pgsql')->hasTable($bankingTable)) {
                DB::connection('pgsql')->table($bankingTable)->delete();
            }
        }
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $this->seedAccount('tenant-0001', 'account-cash');
        $this->seedAccount('tenant-0001', 'account-income');
        $this->seedAccount('tenant-0002', 'account-cash-b');
        $this->seedJournal('tenant-0001', 'journal-0001');
        $this->seedJournal('tenant-0002', 'journal-0002');
    }

    public function test_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::TABLE, [
            'tenant_id', 'idempotency_key', 'journal_id', 'created_at',
        ]));
    }

    public function test_valid_mapping_inserts(): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => 'key-0001',
            'journal_id' => 'journal-0001',
        ]);

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_same_tenant_and_same_key_cannot_insert_twice(): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => 'key-0001',
            'journal_id' => 'journal-0001',
        ]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => 'key-0001',
            'journal_id' => 'journal-0001',
        ]);
    }

    public function test_same_key_may_exist_for_different_tenants(): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => 'key-shared',
            'journal_id' => 'journal-0001',
        ]);

        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0002',
            'idempotency_key' => 'key-shared',
            'journal_id' => 'journal-0002',
        ]);

        $this->assertSame(2, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_mapping_cannot_reference_a_nonexistent_journal(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => 'key-0001',
            'journal_id' => 'journal-does-not-exist',
        ]);
    }

    public function test_mapping_cannot_reference_another_tenants_journal(): void
    {
        $this->expectException(QueryException::class);

        // journal-0002 exists, but only under tenant-0002.
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => 'key-0001',
            'journal_id' => 'journal-0002',
        ]);
    }

    /**
     * Deletion follows the same convention already established for
     * `journal_lines`' own foreign keys — no cascade is introduced. No
     * `ON DELETE` action is specified, so PostgreSQL's default
     * (`NO ACTION`) applies: a `journals` row referenced by a mapping
     * row cannot be deleted directly at the database level, proven
     * here independently of any application code (which itself
     * exposes no Journal delete API at all).
     */
    public function test_referenced_journal_cannot_be_deleted(): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => 'key-0001',
            'journal_id' => 'journal-0001',
        ]);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-0001')->delete();
    }

    public function test_idempotency_key_at_exactly_the_64_character_bound_is_accepted(): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => str_repeat('a', 64),
            'journal_id' => 'journal-0001',
        ]);

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_idempotency_key_one_character_past_the_bound_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => str_repeat('a', 65),
            'journal_id' => 'journal-0001',
        ]);
    }

    /**
     * The table carries exactly the columns this migration's own
     * docblock claims — no mutable status, no attempt counter, no
     * payload JSON, no command snapshot, no `SourceFingerprint`
     * column, and no Actor/Source/Evidence column.
     */
    public function test_table_has_no_extraneous_columns(): void
    {
        $columnNames = Schema::connection('pgsql')->getColumnListing(self::TABLE);
        sort($columnNames);

        $this->assertSame(['created_at', 'idempotency_key', 'journal_id', 'tenant_id'], $columnNames);
    }

    /**
     * `created_at` is present and populated by default, but is
     * observational only — no `updated_at` column exists.
     */
    public function test_created_at_is_present_with_no_updated_at(): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'tenant_id' => 'tenant-0001',
            'idempotency_key' => 'key-0001',
            'journal_id' => 'journal-0001',
        ]);

        $row = DB::connection('pgsql')->table(self::TABLE)->where('idempotency_key', 'key-0001')->first();

        $this->assertNotNull($row->created_at);
        $this->assertFalse(in_array('updated_at', Schema::connection('pgsql')->getColumnListing(self::TABLE), true));
    }

    /**
     * The production migrator: `migrate:rollback` actually drops the
     * table, and the migration re-applies cleanly afterward.
     */
    public function test_migration_rollback_succeeds_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));

        Artisan::call('migrate:rollback', [
            '--database' => 'pgsql',
            '--path' => self::MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::TABLE));

        Artisan::call('migrate', [
            '--database' => 'pgsql',
            '--path' => self::MIGRATION_PATH,
            '--realpath' => false,
            '--force' => true,
        ]);

        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
    }

    private function seedAccount(string $tenantId, string $accountId): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId.$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => 'Asset',
            'account_origin' => 'UserCreated',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);
    }

    private function seedJournal(string $tenantId, string $journalId): void
    {
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => $tenantId,
            'journal_id' => $journalId,
            'state' => 'Draft',
            'financial_date' => '2026-08-15',
        ]);
    }

    private function ensureMigrated(): void
    {
        if (self::$skipReason !== null || self::$migrated) {
            return;
        }

        try {
            DB::connection('pgsql')->select('select 1');
        } catch (\Throwable $e) {
            self::$skipReason = sprintf(
                'A real PostgreSQL instance is not reachable via the "pgsql" connection (%s). '
                .'Run `docker compose up -d postgres` (see docker-compose.yml) to enable this integration test.',
                $e->getMessage(),
            );

            return;
        }

        self::dropTablesDependentOnJournalsAndAccounts();

        // Dependency order matters here: this table's own migration
        // creates a composite foreign key referencing `journals`, so
        // `accounts` and `journals`/`journal_lines` must already exist
        // before `posting_idempotency_keys` itself is (re-)created.
        if (! Schema::connection('pgsql')->hasTable(self::ACCOUNT_TABLE)) {
            self::forceCleanMigration(self::ACCOUNTS_MIGRATION_PATH, [self::ACCOUNT_TABLE]);
        }

        if (! Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE)) {
            self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);
            self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);
            self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
        }

        if (! Schema::connection('pgsql')->hasColumn(self::JOURNAL_TABLE, 'financial_date')) {
            self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
        }

        // `migrate:rollback --path=X` only rolls back the most recent
        // *batch* — dropping the table directly and clearing its
        // migration tracking row is unambiguous regardless of batch
        // history, exactly as already established for
        // `JournalsAndJournalLinesTableMigrationTest`.
        self::forceCleanMigration(self::MIGRATION_PATH, [self::TABLE]);

        self::$migrated = true;
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
