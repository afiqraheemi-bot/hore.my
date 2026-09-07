<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Transactions\Income;

use App\Infrastructure\Transactions\Income\IncomeRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\Feature\Infrastructure\Accounting\Posting\PostingIdempotencyKeysTableMigrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for the production `incomes` table migration
 * (M9, `database/migrations/2026_09_06_235000_create_incomes_table.php`),
 * exercised against a real PostgreSQL instance and the real Laravel
 * migrator, mirroring the precedent already established for
 * {@see PostingIdempotencyKeysTableMigrationTest}.
 *
 * This is schema-only coverage — proving the three tenant-safe
 * composite foreign keys (`journals`, and `accounts` twice, once per
 * role) and the primary key {@see IncomeRepository}
 * relies on.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class IncomesTableMigrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const TABLE = 'incomes';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const MIGRATION_PATH = 'database/migrations/2026_09_06_235000_create_incomes_table.php';

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

        self::cleanSharedAccountingTables();

        $this->seedAccount('tenant-0001', 'account-income', 'Revenue');
        $this->seedAccount('tenant-0001', 'account-cash', 'Asset');
        $this->seedAccount('tenant-0002', 'account-income-b', 'Revenue');
        $this->seedJournal('tenant-0001', 'journal-0001');
        $this->seedJournal('tenant-0002', 'journal-0002');
    }

    public function test_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::TABLE, [
            'id', 'tenant_id', 'journal_id', 'amount', 'currency', 'transaction_date',
            'income_account_id', 'deposit_account_id', 'description', 'evidence_reference', 'recorded_at',
        ]));
    }

    public function test_valid_income_inserts(): void
    {
        $this->insertIncome('income-0001', 'tenant-0001', 'journal-0001', 'account-income', 'account-cash');

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_id_is_the_primary_key(): void
    {
        $this->insertIncome('income-0001', 'tenant-0001', 'journal-0001', 'account-income', 'account-cash');

        $this->expectException(QueryException::class);

        $this->insertIncome('income-0001', 'tenant-0001', 'journal-0001', 'account-income', 'account-cash');
    }

    public function test_income_cannot_reference_a_nonexistent_journal(): void
    {
        $this->expectException(QueryException::class);

        $this->insertIncome('income-0001', 'tenant-0001', 'journal-does-not-exist', 'account-income', 'account-cash');
    }

    public function test_income_cannot_reference_another_tenants_journal(): void
    {
        $this->expectException(QueryException::class);

        // journal-0002 exists, but only under tenant-0002.
        $this->insertIncome('income-0001', 'tenant-0001', 'journal-0002', 'account-income', 'account-cash');
    }

    public function test_income_cannot_reference_a_nonexistent_income_account(): void
    {
        $this->expectException(QueryException::class);

        $this->insertIncome('income-0001', 'tenant-0001', 'journal-0001', 'account-does-not-exist', 'account-cash');
    }

    public function test_income_cannot_reference_another_tenants_deposit_account(): void
    {
        $this->expectException(QueryException::class);

        $this->insertIncome('income-0001', 'tenant-0001', 'journal-0001', 'account-income', 'account-income-b');
    }

    public function test_recorded_at_is_present_and_database_assigned(): void
    {
        $this->insertIncome('income-0001', 'tenant-0001', 'journal-0001', 'account-income', 'account-cash');

        $row = DB::connection('pgsql')->table(self::TABLE)->where('id', 'income-0001')->first();

        $this->assertNotNull($row->recorded_at);
    }

    public function test_evidence_reference_is_nullable(): void
    {
        $this->insertIncome('income-0001', 'tenant-0001', 'journal-0001', 'account-income', 'account-cash');

        $row = DB::connection('pgsql')->table(self::TABLE)->where('id', 'income-0001')->first();

        $this->assertNull($row->evidence_reference);
    }

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

    private function insertIncome(string $id, string $tenantId, string $journalId, string $incomeAccountId, string $depositAccountId): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'journal_id' => $journalId,
            'amount' => 5000,
            'currency' => 'MYR',
            'transaction_date' => '2026-09-06',
            'income_account_id' => $incomeAccountId,
            'deposit_account_id' => $depositAccountId,
            'description' => 'Test income',
            'evidence_reference' => null,
        ]);
    }

    private function seedAccount(string $tenantId, string $accountId, string $accountType): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId.$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => $accountType,
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
