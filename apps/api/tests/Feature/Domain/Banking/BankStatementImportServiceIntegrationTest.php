<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankStatementImportService;
use App\Domain\Banking\BankTransactionDirection;
use App\Domain\Banking\CsvBankStatementParser;
use App\Domain\Banking\Exception\MalformedBankStatementException;
use App\Domain\Banking\MaybankPdfBankStatementParser;
use App\Domain\Banking\XlsxBankStatementParser;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Banking\BankTransactionRepository;
use App\Infrastructure\Banking\ImportBatchRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\TestCase;

/**
 * Integration-level proof for {@see BankStatementImportService} (M17)
 * — exercised against a real PostgreSQL instance, every collaborator
 * real, never stubbed.
 *
 * Directly evidences BNK-001/BNK-003/BNK-004's acceptance criteria:
 * normalized rows persisted exactly, file-level idempotent replay,
 * row-level duplicate skipping, tenant isolation, and atomicity
 * (fault-injection).
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class BankStatementImportServiceIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const BANK_ACCOUNT_TABLE = 'bank_accounts';

    private const IMPORT_BATCH_TABLE = 'bank_statement_import_batches';

    private const BANK_TRANSACTION_TABLE = 'bank_transactions';

    private const ACCOUNT_TABLE = 'accounts';

    private const BANK_ACCOUNT_MIGRATION_PATH = 'database/migrations/2026_09_07_090000_create_bank_accounts_table.php';

    private const IMPORT_BATCH_MIGRATION_PATH = 'database/migrations/2026_09_07_100000_create_bank_statement_import_batches_table.php';

    private const BANK_TRANSACTION_MIGRATION_PATH = 'database/migrations/2026_09_07_110000_create_bank_transactions_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private BankStatementImportService $service;

    private TenantId $tenantA;

    private TenantId $tenantB;

    private BankAccountId $bankAccountA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        // Re-checked on every test, not just once in ensureMigrated():
        // another Banking test class sharing this persistent database
        // (e.g. one proving `accounts`/`bank_accounts` migration
        // reversibility) may drop these tables between this class's own
        // one-time ensureMigrated() and any individual test method here
        // actually running — PHPUnit's test *class* execution order is
        // not alphabetical or otherwise guaranteed.
        foreach ([
            self::BANK_ACCOUNT_TABLE => self::BANK_ACCOUNT_MIGRATION_PATH,
            self::IMPORT_BATCH_TABLE => self::IMPORT_BATCH_MIGRATION_PATH,
            self::BANK_TRANSACTION_TABLE => self::BANK_TRANSACTION_MIGRATION_PATH,
        ] as $table => $migrationPath) {
            if (! Schema::connection('pgsql')->hasTable($table)) {
                self::forceCleanMigration($migrationPath, []);
            }
        }

        // This class does not own `journals`/`journal_lines`/`matches`/
        // `reconciliations` (or any Transactions-domain table) — it
        // only needs `accounts` to be freely deletable. A leftover row
        // in any of them from another test class sharing this same
        // persistent database (a very common fixture id like
        // `account-cash`/`bank-account-0001`) would otherwise block the
        // blanket `accounts`/`bank_accounts` deletes below with the
        // identical foreign-key-violation this codebase already treats
        // as "not this test's concern" everywhere else — see
        // {@see CleansSharedAccountingTables}'s own docblock.
        self::cleanSharedAccountingTables();

        $connection = DB::connection('pgsql');
        $this->service = $this->buildService($connection);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');

        $this->insertAccount($this->tenantA, 'account-bank');
        $this->insertAccount($this->tenantB, 'account-bank-b');

        $this->bankAccountA = BankAccountId::of('bank-account-0001');
        $this->insertBankAccount($this->bankAccountA, $this->tenantA, 'account-bank');
        $this->insertBankAccount(BankAccountId::of('bank-account-b-0001'), $this->tenantB, 'account-bank-b');
    }

    public function test_a_well_formed_statement_is_imported_and_normalized_exactly(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,3000.00,REF001\n"
            ."2026-08-02,Rent payment,1200.00,OUT,1800.00,REF002\n";

        $result = $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));

        $this->assertTrue($result->isNewImport());
        $this->assertSame(2, $result->importBatch()->rowCount());
        $this->assertSame(2, $result->importBatch()->insertedCount());
        $this->assertSame(0, $result->importBatch()->duplicateCount());

        $transactions = $this->bankTransactionRepository()->findByBankAccount($this->tenantA, $this->bankAccountA);
        $this->assertCount(2, $transactions);

        $this->assertSame('2026-08-01', $transactions[0]->transactionDate()->format('Y-m-d'));
        $this->assertSame('Salary credit', $transactions[0]->description());
        $this->assertSame('3000.00', $transactions[0]->amount()->toDecimalString());
        $this->assertSame(BankTransactionDirection::MoneyIn, $transactions[0]->direction());
        $this->assertSame('3000.00', $transactions[0]->balance()?->toDecimalString());
        $this->assertSame('REF001', $transactions[0]->reference());
    }

    public function test_reuploading_the_byte_identical_file_replays_instead_of_duplicating(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n";

        $first = $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));
        $second = $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));

        $this->assertTrue($first->isNewImport());
        $this->assertTrue($second->isReplay());
        $this->assertTrue($first->importBatch()->id()->equals($second->importBatch()->id()));

        $this->assertSame(1, DB::connection('pgsql')->table(self::IMPORT_BATCH_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->count());
    }

    /**
     * BNK-001 (ATS-008 BNK-T059): a fresh import and a replay of the
     * byte-identical file both leave the `journals` table's row count
     * for this Tenant exactly unchanged. Import populates
     * `bank_transactions`/`bank_statement_import_batches` only —
     * {@see BankStatementImportService} has no dependency on any
     * posting service, so this is a real behavioral proof, not merely
     * a restatement of that structural fact.
     */
    public function test_import_leaves_the_journals_table_unchanged_for_a_fresh_import_and_a_replay(): void
    {
        $journalsForTenantA = fn (): int => DB::connection('pgsql')->table('journals')->where('tenant_id', $this->tenantA->toString())->count();

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n";

        $before = $journalsForTenantA();

        $result = $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));
        $this->assertTrue($result->isNewImport());
        $this->assertSame($before, $journalsForTenantA());

        $replay = $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));
        $this->assertTrue($replay->isReplay());
        $this->assertSame($before, $journalsForTenantA());
    }

    public function test_an_overlapping_non_identical_reexport_skips_only_the_duplicate_rows(): void
    {
        $firstCsv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n"
            ."2026-08-02,Rent payment,1200.00,OUT,,\n";

        $this->service->import($this->tenantA, $this->bankAccountA, 'statement-1.csv', $firstCsv, Currency::of('MYR'));

        // Overlaps the 2026-08-02 row (byte-different file, since the
        // filename/row set differs) and adds one genuinely new row.
        $secondCsv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-02,Rent payment,1200.00,OUT,,\n"
            ."2026-08-03,Utility bill,150.00,OUT,,\n";

        $result = $this->service->import($this->tenantA, $this->bankAccountA, 'statement-2.csv', $secondCsv, Currency::of('MYR'));

        $this->assertTrue($result->isNewImport());
        $this->assertSame(2, $result->importBatch()->rowCount());
        $this->assertSame(1, $result->importBatch()->insertedCount());
        $this->assertSame(1, $result->importBatch()->duplicateCount());

        $this->assertSame(3, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->count());
    }

    public function test_a_malformed_statement_is_rejected_and_persists_nothing(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Good row,100.00,IN,,\n"
            ."2026-08-02,Bad row,not-a-number,IN,,\n";

        try {
            $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));
            $this->fail('Expected the malformed row to reject the whole import.');
        } catch (MalformedBankStatementException) {
            // Expected.
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::IMPORT_BATCH_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->count());
    }

    public function test_two_tenants_importing_do_not_interfere(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n";

        $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));
        $this->service->import($this->tenantB, BankAccountId::of('bank-account-b-0001'), 'statement.csv', $csv, Currency::of('MYR'));

        $this->assertSame(1, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->where('tenant_id', $this->tenantA->toString())->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->where('tenant_id', $this->tenantB->toString())->count());
    }

    public function test_two_bank_accounts_with_identical_file_content_do_not_interfere(): void
    {
        $secondBankAccountId = BankAccountId::of('bank-account-0002');
        $this->insertBankAccount($secondBankAccountId, $this->tenantA, 'account-bank');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n";

        $first = $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));
        $second = $this->service->import($this->tenantA, $secondBankAccountId, 'statement.csv', $csv, Currency::of('MYR'));

        $this->assertTrue($first->isNewImport());
        $this->assertTrue($second->isNewImport());
        $this->assertSame(2, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->count());
    }

    public function test_a_forced_bank_transaction_insert_failure_rolls_back_the_entire_import(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement('ALTER TABLE bank_transactions ADD CONSTRAINT force_test_bank_transaction_failure CHECK (1 = 0)');

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Salary credit,3000.00,IN,,\n";

        try {
            try {
                $this->service->import($this->tenantA, $this->bankAccountA, 'statement.csv', $csv, Currency::of('MYR'));
                $this->fail('Expected the forced CHECK constraint to reject the BankTransaction insert.');
            } catch (QueryException) {
                // Expected.
            }
        } finally {
            $connection->statement('ALTER TABLE bank_transactions DROP CONSTRAINT force_test_bank_transaction_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::IMPORT_BATCH_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->count());
    }

    /**
     * The caller-visible loser outcome remains deliberately unspecified,
     * but the database result is non-negotiable: a genuine two-process
     * race over byte-identical input leaves exactly one complete batch and
     * one normalized source row, never duplicate or partial persistence.
     */
    public function test_concurrent_identical_imports_remain_atomic_and_duplicate_free(): void
    {
        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Concurrent salary,3000.00,IN,,CONCURRENT-SAME\n";

        [$resultA, $resultB] = $this->raceImportWorkers($csv, $csv);
        $successful = array_filter(
            [$resultA, $resultB],
            static fn (array $result): bool => ($result['status'] ?? null) === 'success',
        );

        $this->assertNotEmpty($successful, 'At least one concurrent identical import must commit.');
        $this->assertSame(1, DB::connection('pgsql')->table(self::IMPORT_BATCH_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->count());
        $this->assertPersistedImportCountsAreExact();
    }

    /**
     * Two different statement exports overlap on one source row and each
     * carry one unique row. Depending on serialization, PostgreSQL may
     * commit only the winner or the winner followed by a clean duplicate-
     * aware import. Both outcomes are safe; duplicate or partially-owned
     * rows are not.
     */
    public function test_concurrent_overlapping_reexports_cannot_create_duplicates_or_partial_batches(): void
    {
        $csvA = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Shared row,100.00,IN,,CONCURRENT-SHARED\n"
            ."2026-08-02,Only in A,25.00,OUT,,CONCURRENT-A\n";
        $csvB = "date,description,amount,direction,balance,reference\n"
            ."2026-08-01,Shared row,100.00,IN,,CONCURRENT-SHARED\n"
            ."2026-08-03,Only in B,30.00,OUT,,CONCURRENT-B\n";

        [$resultA, $resultB] = $this->raceImportWorkers($csvA, $csvB);
        $successful = array_filter(
            [$resultA, $resultB],
            static fn (array $result): bool => ($result['status'] ?? null) === 'success',
        );

        $this->assertNotEmpty($successful, 'At least one concurrent overlapping import must commit.');
        $this->assertContains(DB::connection('pgsql')->table(self::IMPORT_BATCH_TABLE)->count(), [1, 2]);
        $this->assertContains(DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)->count(), [2, 3]);
        $this->assertSame(1, DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE)
            ->where('reference', 'CONCURRENT-SHARED')
            ->count());
        $this->assertPersistedImportCountsAreExact();

        $orphanCount = DB::connection('pgsql')->table(self::BANK_TRANSACTION_TABLE.' as transaction')
            ->leftJoin(self::IMPORT_BATCH_TABLE.' as batch', 'batch.id', '=', 'transaction.import_batch_id')
            ->whereNull('batch.id')
            ->count();

        $this->assertSame(0, $orphanCount);
    }

    // --- Fixtures and helpers ------------------------------------------

    private function bankTransactionRepository(): BankTransactionRepository
    {
        return new BankTransactionRepository(DB::connection('pgsql'));
    }

    private function assertPersistedImportCountsAreExact(): void
    {
        $batches = DB::connection('pgsql')->table(self::IMPORT_BATCH_TABLE)
            ->get(['row_count', 'inserted_count', 'duplicate_count']);

        foreach ($batches as $batch) {
            $this->assertSame(
                (int) $batch->row_count,
                (int) $batch->inserted_count + (int) $batch->duplicate_count,
            );
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function raceImportWorkers(string $csvA, string $csvB): array
    {
        $temporaryDirectory = sys_get_temp_dir();
        $readyA = tempnam($temporaryDirectory, 'import_ready_a_');
        $readyB = tempnam($temporaryDirectory, 'import_ready_b_');
        $goFile = tempnam($temporaryDirectory, 'import_go_');
        $resultA = tempnam($temporaryDirectory, 'import_result_a_');
        $resultB = tempnam($temporaryDirectory, 'import_result_b_');

        foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $file) {
            unlink($file);
        }

        $workerScript = base_path('tests/bin/concurrent_bank_import_worker.php');
        $commonArguments = [$this->tenantA->toString(), $this->bankAccountA->toString()];

        $processA = proc_open(
            ['php', $workerScript, ...$commonArguments, 'statement-a.csv', base64_encode($csvA), $readyA, $goFile, $resultA],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $processB = proc_open(
            ['php', $workerScript, ...$commonArguments, 'statement-b.csv', base64_encode($csvB), $readyB, $goFile, $resultB],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB,
        );

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        try {
            $deadline = microtime(true) + 5.0;
            while (! (file_exists($readyA) && file_exists($readyB))) {
                if (microtime(true) > $deadline) {
                    $this->fail('Timed out waiting for both Bank Import workers to signal ready.');
                }

                usleep(2000);
            }

            touch($goFile);

            foreach ([$processA, $processB] as $process) {
                proc_close($process);
            }

            $deadline = microtime(true) + 5.0;
            while (! (file_exists($resultA) && file_exists($resultB))) {
                if (microtime(true) > $deadline) {
                    $this->fail('Timed out waiting for both Bank Import worker results.');
                }

                usleep(2000);
            }

            /** @var array<string, mixed> $resultAData */
            $resultAData = json_decode((string) file_get_contents($resultA), true, flags: JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $resultBData */
            $resultBData = json_decode((string) file_get_contents($resultB), true, flags: JSON_THROW_ON_ERROR);

            return [$resultAData, $resultBData];
        } finally {
            foreach ([$pipesA ?? [], $pipesB ?? []] as $pipes) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }

            foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $file) {
                @unlink($file);
            }
        }
    }

    private function buildService(ConnectionInterface $connection): BankStatementImportService
    {
        return new BankStatementImportService(
            $connection,
            new CsvBankStatementParser,
            new XlsxBankStatementParser,
            new MaybankPdfBankStatementParser,
            new ImportBatchRepository($connection),
            new BankTransactionRepository($connection),
        );
    }

    private function insertAccount(TenantId $tenantId, string $accountId): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId->toString().$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => 'Asset',
            'account_origin' => 'UserCreated',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);
    }

    private function insertBankAccount(BankAccountId $bankAccountId, TenantId $tenantId, string $linkedAccountId): void
    {
        DB::connection('pgsql')->table(self::BANK_ACCOUNT_TABLE)->insert([
            'id' => $bankAccountId->toString(),
            'tenant_id' => $tenantId->toString(),
            'linked_account_id' => $linkedAccountId,
            'bank_name' => 'Maybank',
            'account_number_last4' => '1234',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
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

        if (! Schema::connection('pgsql')->hasTable(self::BANK_TRANSACTION_TABLE)) {
            self::forceCleanMigration(self::BANK_TRANSACTION_MIGRATION_PATH, [self::BANK_TRANSACTION_TABLE]);
        }

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
