<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Banking;

use App\Infrastructure\Banking\BankTransactionRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for the production
 * `bank_statement_import_batches` and `bank_transactions` table
 * migrations (M17), exercised together against a real PostgreSQL
 * instance since the latter's own foreign key depends on the former —
 * schema-only coverage proving both tables' composite/simple foreign
 * keys, the `direction` canonical-value `CHECK` constraint, the
 * `(bank_account_id, file_hash)` file-level uniqueness (BNK-004), and
 * the row-level natural-key uniqueness {@see BankTransactionRepository}
 * relies on.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class BankTransactionsTableMigrationTest extends TestCase
{
    private const TABLE = 'bank_transactions';

    private const IMPORT_BATCH_TABLE = 'bank_statement_import_batches';

    private const BANK_ACCOUNT_TABLE = 'bank_accounts';

    private const ACCOUNT_TABLE = 'accounts';

    private const MIGRATION_PATH = 'database/migrations/2026_09_07_110000_create_bank_transactions_table.php';

    private const IMPORT_BATCH_MIGRATION_PATH = 'database/migrations/2026_09_07_100000_create_bank_statement_import_batches_table.php';

    private const BANK_ACCOUNT_MIGRATION_PATH = 'database/migrations/2026_09_07_090000_create_bank_accounts_table.php';

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
        DB::connection('pgsql')->table(self::IMPORT_BATCH_TABLE)->delete();
        DB::connection('pgsql')->table(self::BANK_ACCOUNT_TABLE)->delete();

        foreach (['period_closures', 'posting_idempotency_keys', 'posting_source_fingerprints', 'audit_events', 'journal_evidence_links', 'expenses', 'incomes', 'transfers', 'owner_equity_transactions', 'journal_lines', 'journals'] as $table) {
            if (Schema::connection('pgsql')->hasTable($table)) {
                DB::connection('pgsql')->table($table)->delete();
            }
        }

        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $this->seedAccount('tenant-0001', 'account-bank');
        $this->seedBankAccount('bank-account-0001', 'tenant-0001', 'account-bank');
        $this->seedImportBatch('batch-0001', 'tenant-0001', 'bank-account-0001');
    }

    public function test_import_batch_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::IMPORT_BATCH_TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::IMPORT_BATCH_TABLE, [
            'id', 'tenant_id', 'bank_account_id', 'file_hash', 'original_filename', 'row_count', 'inserted_count', 'duplicate_count', 'imported_at',
        ]));
    }

    public function test_import_batch_file_hash_is_unique_per_bank_account(): void
    {
        $this->expectException(QueryException::class);

        $this->seedImportBatch('batch-0002', 'tenant-0001', 'bank-account-0001', 'same-hash');
        $this->seedImportBatch('batch-0003', 'tenant-0001', 'bank-account-0001', 'same-hash');
    }

    public function test_bank_transactions_table_creates_successfully(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
        $this->assertTrue(Schema::connection('pgsql')->hasColumns(self::TABLE, [
            'id', 'tenant_id', 'bank_account_id', 'import_batch_id', 'transaction_date', 'description', 'amount', 'currency', 'direction', 'balance', 'reference', 'created_at',
        ]));
    }

    public function test_valid_bank_transaction_inserts(): void
    {
        $this->insertBankTransaction('bank-txn-0001', 'REF001');

        $this->assertSame(1, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_direction_must_be_canonical(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => 'bank-txn-0001',
            'tenant_id' => 'tenant-0001',
            'bank_account_id' => 'bank-account-0001',
            'import_batch_id' => 'batch-0001',
            'transaction_date' => '2026-08-01',
            'description' => 'Test',
            'amount' => 10000,
            'currency' => 'MYR',
            'direction' => 'Sideways',
            'balance' => null,
            'reference' => '',
        ]);
    }

    public function test_natural_key_is_unique(): void
    {
        $this->insertBankTransaction('bank-txn-0001', 'REF001');

        $this->expectException(QueryException::class);

        $this->insertBankTransaction('bank-txn-0002', 'REF001');
    }

    public function test_a_different_reference_is_not_a_duplicate(): void
    {
        $this->insertBankTransaction('bank-txn-0001', 'REF001');
        $this->insertBankTransaction('bank-txn-0002', 'REF002');

        $this->assertSame(2, DB::connection('pgsql')->table(self::TABLE)->count());
    }

    public function test_cannot_reference_a_nonexistent_import_batch(): void
    {
        $this->expectException(QueryException::class);

        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => 'bank-txn-0001',
            'tenant_id' => 'tenant-0001',
            'bank_account_id' => 'bank-account-0001',
            'import_batch_id' => 'batch-does-not-exist',
            'transaction_date' => '2026-08-01',
            'description' => 'Test',
            'amount' => 10000,
            'currency' => 'MYR',
            'direction' => 'MoneyIn',
            'balance' => null,
            'reference' => '',
        ]);
    }

    public function test_migration_rollback_succeeds_cleanly(): void
    {
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));

        Artisan::call('migrate:rollback', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);
        $this->assertFalse(Schema::connection('pgsql')->hasTable(self::TABLE));

        Artisan::call('migrate', ['--database' => 'pgsql', '--path' => self::MIGRATION_PATH, '--realpath' => false, '--force' => true]);
        $this->assertTrue(Schema::connection('pgsql')->hasTable(self::TABLE));
    }

    private function insertBankTransaction(string $id, string $reference): void
    {
        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => $id,
            'tenant_id' => 'tenant-0001',
            'bank_account_id' => 'bank-account-0001',
            'import_batch_id' => 'batch-0001',
            'transaction_date' => '2026-08-01',
            'description' => 'Test transaction',
            'amount' => 10000,
            'currency' => 'MYR',
            'direction' => 'MoneyIn',
            'balance' => null,
            'reference' => $reference,
        ]);
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

    private function seedBankAccount(string $id, string $tenantId, string $linkedAccountId): void
    {
        DB::connection('pgsql')->table(self::BANK_ACCOUNT_TABLE)->insert([
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

    private function seedImportBatch(string $id, string $tenantId, string $bankAccountId, string $fileHash = 'hash-0001'): void
    {
        DB::connection('pgsql')->table(self::IMPORT_BATCH_TABLE)->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'bank_account_id' => $bankAccountId,
            'file_hash' => $fileHash,
            'original_filename' => 'statement.csv',
            'row_count' => 0,
            'inserted_count' => 0,
            'duplicate_count' => 0,
            'imported_at' => now(),
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

        if (! Schema::connection('pgsql')->hasTable(self::BANK_ACCOUNT_TABLE)) {
            self::forceCleanMigration(self::BANK_ACCOUNT_MIGRATION_PATH, [self::BANK_ACCOUNT_TABLE]);
        }

        if (! Schema::connection('pgsql')->hasTable(self::IMPORT_BATCH_TABLE)) {
            self::forceCleanMigration(self::IMPORT_BATCH_MIGRATION_PATH, [self::IMPORT_BATCH_TABLE]);
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

        Artisan::call('migrate', ['--database' => 'pgsql', '--path' => $migrationPath, '--realpath' => false, '--force' => true]);
    }
}
