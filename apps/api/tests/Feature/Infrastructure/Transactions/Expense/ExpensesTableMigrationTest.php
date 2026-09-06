<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Transactions\Expense;

use App\Infrastructure\Transactions\Expense\ExpenseRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Infrastructure\Accounting\Posting\PostingIdempotencyKeysTableMigrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for the production `expenses` table migration
 * (M7, `database/migrations/2026_09_06_220000_create_expenses_table.php`),
 * exercised against a real PostgreSQL instance and the real Laravel
 * migrator, mirroring the precedent already established for
 * {@see PostingIdempotencyKeysTableMigrationTest}.
 *
 * This is schema-only coverage — proving the three tenant-safe
 * composite foreign keys (`journals`, and `accounts` twice, once per
 * role) and the primary key {@see ExpenseRepository}
 * relies on.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class ExpensesTableMigrationTest extends TestCase
{
    private const TABLE = 'expenses';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const MIGRATION_PATH = 'database/migrations/2026_09_06_220000_create_expenses_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

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
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $this->seedAccount('tenant-0001', 'account-expense');
        $this->seedAccount('tenant-0001', 'account-cash');
        $this->seedAccount('tenant-0002', 'account-expense-b');
        $this->seedJournal('tenant-0001', 'journal-0001');
        $this->seedJournal('tenant-0002', 'journal-0002');
    }

    public function test_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::TABLE, [
            'id', 'tenant_id', 'journal_id', 'amount', 'currency', 'transaction_date',
            'expense_account_id', 'payment_account_id', 'description', 'evidence_reference', 'recorded_at',
        ]));
    }

    public function test_valid_expense_inserts(): void
    {
        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-0001', 'account-expense', 'account-cash');

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_id_is_the_primary_key(): void
    {
        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-0001', 'account-expense', 'account-cash');

        $this->expectException(QueryException::class);

        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-0001', 'account-expense', 'account-cash');
    }

    public function test_expense_cannot_reference_a_nonexistent_journal(): void
    {
        $this->expectException(QueryException::class);

        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-does-not-exist', 'account-expense', 'account-cash');
    }

    public function test_expense_cannot_reference_another_tenants_journal(): void
    {
        $this->expectException(QueryException::class);

        // journal-0002 exists, but only under tenant-0002.
        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-0002', 'account-expense', 'account-cash');
    }

    public function test_expense_cannot_reference_a_nonexistent_expense_account(): void
    {
        $this->expectException(QueryException::class);

        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-0001', 'account-does-not-exist', 'account-cash');
    }

    public function test_expense_cannot_reference_another_tenants_payment_account(): void
    {
        $this->expectException(QueryException::class);

        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-0001', 'account-expense', 'account-expense-b');
    }

    public function test_recorded_at_is_present_and_database_assigned(): void
    {
        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-0001', 'account-expense', 'account-cash');

        $row = DB::connection('pgsql')->table(self::TABLE)->where('id', 'expense-0001')->first();

        $this->assertNotNull($row->recorded_at);
    }

    public function test_evidence_reference_is_nullable(): void
    {
        $this->insertExpense('expense-0001', 'tenant-0001', 'journal-0001', 'account-expense', 'account-cash');

        $row = DB::connection('pgsql')->table(self::TABLE)->where('id', 'expense-0001')->first();

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

    private function insertExpense(string $id, string $tenantId, string $journalId, string $expenseAccountId, string $paymentAccountId): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'journal_id' => $journalId,
            'amount' => 5000,
            'currency' => 'MYR',
            'transaction_date' => '2026-09-06',
            'expense_account_id' => $expenseAccountId,
            'payment_account_id' => $paymentAccountId,
            'description' => 'Test expense',
            'evidence_reference' => null,
        ]);
    }

    private function seedAccount(string $tenantId, string $accountId): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId.$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => 'Expense',
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
