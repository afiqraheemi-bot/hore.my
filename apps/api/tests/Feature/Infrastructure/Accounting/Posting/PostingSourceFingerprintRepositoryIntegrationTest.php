<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\SourceFingerprint;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Posting\Exception\DuplicateSourceFingerprintException;
use App\Infrastructure\Accounting\Posting\PostingSourceFingerprintRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\DropsTablesDependentOnJournalsAndAccounts;
use Tests\TestCase;

/**
 * Integration-level proof for {@see PostingSourceFingerprintRepository},
 * exercised against a real PostgreSQL instance and the real production
 * `posting_source_fingerprints`/`journals`/`accounts` migrations —
 * mirroring `PostingIdempotencyRepositoryIntegrationTest` (M4-T16)
 * exactly, for the entirely separate duplicate-source guarantee
 * AETS-007 §6.2 requires.
 *
 * This is a persistence-boundary proof only. No `PostingCommand`
 * requirement-detection policy (whether a given command needs a
 * Source Fingerprint at all — §6.2/§26 defer that to a future calling
 * module) or Posting orchestration is exercised here.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingSourceFingerprintRepositoryIntegrationTest extends TestCase
{
    use DropsTablesDependentOnJournalsAndAccounts;

    private const TABLE = 'posting_source_fingerprints';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const MIGRATION_PATH = 'database/migrations/2026_09_05_180000_create_posting_source_fingerprints_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private PostingSourceFingerprintRepository $repository;

    private TenantId $tenantA;

    private TenantId $tenantB;

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
        if (Schema::connection('pgsql')->hasTable('quotations')) {
            DB::connection('pgsql')->table('quotation_lines')->delete();
            DB::connection('pgsql')->table('quotations')->delete();
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

        $this->repository = new PostingSourceFingerprintRepository(DB::connection('pgsql'));
        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');

        $this->seedAccount('tenant-0001', 'account-cash');
        $this->seedJournal('tenant-0001', 'journal-0001');
        $this->seedJournal('tenant-0001', 'journal-0002');
        $this->seedJournal('tenant-0002', 'journal-0003');
    }

    public function test_missing_mapping_returns_null(): void
    {
        $result = $this->repository->find($this->tenantA, SourceFingerprint::of('fp-0001'));

        $this->assertNull($result);
    }

    public function test_recorded_mapping_resolves_exact_journal_id(): void
    {
        $fingerprint = SourceFingerprint::of('fp-0001');
        $this->repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));

        $result = $this->repository->find($this->tenantA, $fingerprint);

        $this->assertNotNull($result);
        $this->assertTrue($result->equals(JournalId::of('journal-0001')));
    }

    public function test_same_tenant_and_fingerprint_second_insert_is_rejected(): void
    {
        $fingerprint = SourceFingerprint::of('fp-0001');
        $this->repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));

        $this->expectException(DuplicateSourceFingerprintException::class);

        $this->repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));
    }

    public function test_conflicting_journal_id_under_same_tenant_and_fingerprint_is_rejected(): void
    {
        $fingerprint = SourceFingerprint::of('fp-0001');
        $this->repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));

        $this->expectException(DuplicateSourceFingerprintException::class);

        $this->repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0002'));
    }

    public function test_same_fingerprint_under_different_tenants_succeeds_independently(): void
    {
        $fingerprint = SourceFingerprint::of('fp-shared');

        $this->repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));
        $this->repository->record($this->tenantB, $fingerprint, JournalId::of('journal-0003'));

        $this->assertTrue($this->repository->find($this->tenantA, $fingerprint)->equals(JournalId::of('journal-0001')));
        $this->assertTrue($this->repository->find($this->tenantB, $fingerprint)->equals(JournalId::of('journal-0003')));
    }

    public function test_cross_tenant_journal_reference_is_rejected_by_foreign_key(): void
    {
        // journal-0003 exists, but only under tenant-0002.
        $this->expectException(QueryException::class);

        $this->repository->record($this->tenantA, SourceFingerprint::of('fp-0001'), JournalId::of('journal-0003'));
    }

    public function test_nonexistent_journal_reference_is_rejected_by_foreign_key(): void
    {
        $this->expectException(QueryException::class);

        $this->repository->record($this->tenantA, SourceFingerprint::of('fp-0001'), JournalId::of('journal-does-not-exist'));
    }

    public function test_record_inside_an_outer_transaction_rolls_back_with_that_transaction(): void
    {
        $connection = DB::connection('pgsql');
        $repository = new PostingSourceFingerprintRepository($connection);
        $fingerprint = SourceFingerprint::of('fp-0001');

        $connection->beginTransaction();
        $repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));
        $connection->rollBack();

        $this->assertNull($this->repository->find($this->tenantA, $fingerprint));
    }

    public function test_record_inside_an_outer_transaction_commits_with_that_transaction(): void
    {
        $connection = DB::connection('pgsql');
        $repository = new PostingSourceFingerprintRepository($connection);
        $fingerprint = SourceFingerprint::of('fp-0001');

        $connection->beginTransaction();
        $repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));
        $connection->commit();

        $result = $this->repository->find($this->tenantA, $fingerprint);
        $this->assertNotNull($result);
        $this->assertTrue($result->equals(JournalId::of('journal-0001')));
    }

    public function test_record_participates_in_a_caller_owned_transaction_closure(): void
    {
        $connection = DB::connection('pgsql');
        $repository = new PostingSourceFingerprintRepository($connection);
        $fingerprint = SourceFingerprint::of('fp-0001');

        try {
            $connection->transaction(function () use ($connection, $repository, $fingerprint): void {
                $repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));
                // Something else in the same outer transaction fails —
                // this repository must not have committed on its own.
                $connection->statement('select 1/0');
            });
            $this->fail('Expected the outer transaction to fail and roll back.');
        } catch (QueryException) {
            // Expected: division-by-zero failure inside the transaction
            // closure, which Laravel rolls back automatically.
        }

        $this->assertNull($this->repository->find($this->tenantA, $fingerprint));
    }

    public function test_no_existing_mapping_is_mutated_by_a_failed_duplicate_insertion(): void
    {
        $fingerprint = SourceFingerprint::of('fp-0001');
        $this->repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0001'));

        try {
            $this->repository->record($this->tenantA, $fingerprint, JournalId::of('journal-0002'));
            $this->fail('Expected the duplicate insert to be rejected.');
        } catch (DuplicateSourceFingerprintException) {
            // Expected.
        }

        $result = $this->repository->find($this->tenantA, $fingerprint);
        $this->assertNotNull($result);
        $this->assertTrue($result->equals(JournalId::of('journal-0001')));
    }

    /**
     * Proves genuine database-level contention on the same (Tenant,
     * Source Fingerprint) pair between two independent connections —
     * the second attempt cannot silently proceed past the first's
     * open, uncommitted insert. This establishes only the concurrency
     * primitive the real `PRIMARY KEY` constraint provides; it does
     * not implement or prove any loser-retry orchestration, which
     * remains future work.
     */
    public function test_two_concurrent_attempts_to_create_the_same_mapping_do_not_both_succeed(): void
    {
        $fingerprint = SourceFingerprint::of('fp-0001');

        config(['database.connections.pgsql_secondary' => config('database.connections.pgsql')]);
        DB::purge('pgsql_secondary');
        $secondConnection = DB::connection('pgsql_secondary');
        $secondConnection->statement("set lock_timeout = '200ms'");
        $secondRepository = new PostingSourceFingerprintRepository($secondConnection);

        $firstConnection = DB::connection('pgsql');
        $firstConnection->beginTransaction();
        $firstConnection->table(self::TABLE)->insert([
            'tenant_id' => $this->tenantA->toString(),
            'source_fingerprint' => $fingerprint->toString(),
            'journal_id' => 'journal-0001',
        ]);

        try {
            $secondRepository->record($this->tenantA, $fingerprint, JournalId::of('journal-0002'));
            $this->fail('Expected the concurrent record() to block on the first, uncommitted insert and then fail.');
        } catch (QueryException) {
            // Expected: the second connection could not proceed within
            // its lock_timeout, proving it was genuinely blocked by the
            // first connection's open, uncommitted insert on the same
            // fingerprint rather than racing past it.
        } finally {
            $firstConnection->rollBack();
            DB::purge('pgsql_secondary');
        }

        $this->assertNull($this->repository->find($this->tenantA, $fingerprint));
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
        // before `posting_source_fingerprints` itself is (re-)created.
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
