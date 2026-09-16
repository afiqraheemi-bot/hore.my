<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Banking;

use App\Infrastructure\Banking\BankAccountRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for the production `bank_accounts` table
 * migration (M17), exercised against a real PostgreSQL instance —
 * schema-only coverage proving the composite foreign key onto
 * `accounts` and the primary key {@see BankAccountRepository} relies
 * on.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class BankAccountsTableMigrationTest extends TestCase
{
    private const TABLE = 'bank_accounts';

    private const ACCOUNT_TABLE = 'accounts';

    private const MIGRATION_PATH = 'database/migrations/2026_09_07_090000_create_bank_accounts_table.php';

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

        foreach (['reconciliation_reopenings', 'matches', 'bank_transactions', 'reconciliations', 'bank_statement_import_batches'] as $bankingTable) {
            if (Schema::connection('pgsql')->hasTable($bankingTable)) {
                DB::connection('pgsql')->table($bankingTable)->delete();
            }
        }
        DB::connection('pgsql')->table(self::TABLE)->delete();

        foreach (['period_closures', 'posting_idempotency_keys', 'posting_source_fingerprints', 'audit_events', 'journal_evidence_links', 'expenses', 'incomes', 'transfers', 'owner_equity_transactions', 'journal_lines', 'journals'] as $table) {
            if (Schema::connection('pgsql')->hasTable($table)) {
                DB::connection('pgsql')->table($table)->delete();
            }
        }

        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $this->seedAccount('tenant-0001', 'account-bank', 'Asset');
        $this->seedAccount('tenant-0002', 'account-bank-b', 'Asset');
    }

    public function test_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::TABLE, [
            'id', 'tenant_id', 'linked_account_id', 'bank_name', 'account_number_last4', 'active', 'created_at', 'updated_at',
        ]));
    }

    public function test_valid_bank_account_inserts(): void
    {
        $this->insertBankAccount('bank-account-0001', 'tenant-0001', 'account-bank');

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_id_is_the_primary_key(): void
    {
        $this->insertBankAccount('bank-account-0001', 'tenant-0001', 'account-bank');

        $this->expectException(QueryException::class);

        $this->insertBankAccount('bank-account-0001', 'tenant-0001', 'account-bank');
    }

    public function test_cannot_reference_a_nonexistent_account(): void
    {
        $this->expectException(QueryException::class);

        $this->insertBankAccount('bank-account-0001', 'tenant-0001', 'account-does-not-exist');
    }

    public function test_cannot_reference_another_tenants_account(): void
    {
        $this->expectException(QueryException::class);

        $this->insertBankAccount('bank-account-0001', 'tenant-0001', 'account-bank-b');
    }

    public function test_migration_rollback_succeeds_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));

        // Every table (transitively) foreign-keyed onto bank_accounts,
        // dropped first — not recreated here; each has its own test
        // class responsible for re-migrating itself — so PostgreSQL
        // never refuses this rollback with "dependent objects still
        // exist." May have been recreated by another test class
        // between this class's own setUp() and this specific test
        // method running; PHPUnit's test *class* execution order is
        // not guaranteed.
        foreach (['reconciliation_completion_snapshots', 'matches', 'reconciliation_reopenings', 'bank_transactions', 'reconciliations', 'bank_statement_import_batches'] as $dependentTable) {
            Schema::connection('pgsql')->dropIfExists($dependentTable);
        }

        Artisan::call('migrate:rollback', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);
        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::TABLE));

        Artisan::call('migrate', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
    }

    private function insertBankAccount(string $id, string $tenantId, string $linkedAccountId): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'linked_account_id' => $linkedAccountId,
            'bank_name' => 'Maybank',
            'account_number_last4' => '1234',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
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

        // `bank_statement_import_batches` and `bank_transactions` (M17)
        // each carry a foreign key onto this table — their mere
        // existence, not any row in them, blocks `DROP TABLE
        // bank_accounts` regardless of `--force`. Not this test's
        // concern; not recreated here, mirroring the identical
        // treatment this codebase already gives every other dependent
        // table in this position.
        Schema::connection('pgsql')->dropIfExists('reconciliation_completion_snapshots');
        Schema::connection('pgsql')->dropIfExists('reconciliation_reopenings');
        Schema::connection('pgsql')->dropIfExists('matches');
        Schema::connection('pgsql')->dropIfExists('bank_transactions');
        Schema::connection('pgsql')->dropIfExists('reconciliations');
        Schema::connection('pgsql')->dropIfExists('bank_statement_import_batches');

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

        Artisan::call('migrate', ['--database' => 'pgsql', '--path' => $migrationPath, '--realpath' => false, '--force' => true]);
    }
}
