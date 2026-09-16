<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Banking;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankStatementImportService;
use App\Domain\Banking\CsvBankStatementParser;
use App\Domain\Banking\Exception\InvalidReconciliationStateTransitionException;
use App\Domain\Banking\Exception\ReconciliationComputationExceedsSupportedRangeException;
use App\Domain\Banking\Exception\ReconciliationNotBalancedException;
use App\Domain\Banking\Exception\ReconciliationReopenRequiresReasonException;
use App\Domain\Banking\Reconciliation;
use App\Domain\Banking\ReconciliationService;
use App\Domain\Banking\ReconciliationState;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Banking\BankAccountRepository;
use App\Infrastructure\Banking\BankTransactionRepository;
use App\Infrastructure\Banking\ImportBatchRepository;
use App\Infrastructure\Banking\ReconciliationRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\TestCase;

/**
 * Integration-level proof for {@see ReconciliationService} (M18, SRS
 * BNK-006/BNK-007) — exercised against a real PostgreSQL instance.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class ReconciliationServiceIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const ACCOUNT_TABLE = 'accounts';

    private const RECONCILIATION_TABLE = 'reconciliations';

    private const REOPENING_TABLE = 'reconciliation_reopenings';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private ReconciliationService $reconciliationService;

    private BankStatementImportService $importService;

    private TenantId $tenant;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        // Re-checked on every test, not just once in ensureMigrated():
        // another Banking test class sharing this persistent database
        // may drop `reconciliations`/`reconciliation_reopenings`
        // between this class's own one-time ensureMigrated() and any
        // individual test method here actually running — PHPUnit's test
        // *class* execution order is not alphabetical or otherwise
        // guaranteed.
        if (! Schema::connection('pgsql')->hasTable(self::RECONCILIATION_TABLE)) {
            self::forceCleanMigration('database/migrations/2026_09_07_130000_create_reconciliations_table.php', []);
        }

        if (! Schema::connection('pgsql')->hasTable(self::REOPENING_TABLE)) {
            self::forceCleanMigration('database/migrations/2026_09_07_140000_create_reconciliation_reopenings_table.php', []);
        }

        self::cleanSharedAccountingTables();

        $connection = DB::connection('pgsql');
        $this->tenant = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');

        $this->insertAccount('account-bank', 'Asset');

        $this->importService = new BankStatementImportService(
            $connection,
            new CsvBankStatementParser,
            new ImportBatchRepository($connection),
            new BankTransactionRepository($connection),
        );

        $this->reconciliationService = new ReconciliationService(
            $connection,
            new BankTransactionRepository($connection),
            new ReconciliationRepository($connection),
        );

        $bankAccount = BankAccount::register(BankAccountId::of('bank-account-0001'), $this->tenant, AccountId::of('account-bank'), 'Maybank', null);
        (new BankAccountRepository($connection))->save($bankAccount);
    }

    public function test_opening_a_reconciliation_starts_in_draft(): void
    {
        $reconciliation = $this->open('1000.00', '1000.00');

        $this->assertSame(ReconciliationState::Draft, $reconciliation->state());
        $this->assertSame(1, DB::connection('pgsql')->table(self::RECONCILIATION_TABLE)->count());
    }

    /**
     * Regression: the query builder does not auto-populate `created_at`/
     * `updated_at` the way Eloquent would — an earlier version of
     * `ReconciliationRepository::record()` omitted them entirely,
     * leaving the column `NULL` and causing every later
     * `Reconciliation::reconstitute()` to crash on the very next
     * `findById()`/`getById()` call (any state transition beyond the
     * first).
     */
    public function test_created_at_is_persisted_and_survives_a_reload(): void
    {
        $reconciliation = $this->open('1000.00', '1000.00');

        $reloaded = (new ReconciliationRepository(DB::connection('pgsql')))->getById($this->tenant, $reconciliation->id());

        $this->assertSame($reconciliation->createdAt()->format('Y-m-d H:i:s'), $reloaded->createdAt()->format('Y-m-d H:i:s'));
    }

    public function test_difference_is_zero_when_imported_transactions_reconcile_exactly(): void
    {
        $this->importStatement("2026-08-01,Deposit,500.00,IN,,\n2026-08-15,Withdrawal,200.00,OUT,,\n");

        $reconciliation = $this->open('1000.00', '1300.00');

        $difference = $this->reconciliationService->computeDifference($this->tenant, $reconciliation);

        $this->assertTrue($difference->isZero());
    }

    public function test_difference_is_nonzero_when_a_transaction_is_missing(): void
    {
        $this->importStatement("2026-08-01,Deposit,500.00,IN,,\n");

        // Stated closing balance implies an extra 200.00 that was never
        // imported.
        $reconciliation = $this->open('1000.00', '1300.00');

        $difference = $this->reconciliationService->computeDifference($this->tenant, $reconciliation);

        $this->assertFalse($difference->isZero());
        $this->assertSame('200.00', $difference->amount()->toDecimalString());
    }

    /**
     * BNK-020 (AETS-008 §12.9): an overdraft mid-period — where imported
     * MoneyOut exceeds opening balance plus imported MoneyIn at the
     * point Money would need to go negative to represent the implied
     * running balance — fails closed with a distinct, explicit
     * exception rather than an incorrect or silently wrong difference.
     * Overdraft support itself remains out of scope; this only proves
     * the boundary fails safely instead of lying.
     */
    public function test_an_implied_overdraft_fails_closed_instead_of_computing_a_wrong_difference(): void
    {
        $this->importStatement("2026-08-01,Large withdrawal,900.00,OUT,,\n");

        $reconciliation = $this->open('100.00', '0.01');

        $this->expectException(ReconciliationComputationExceedsSupportedRangeException::class);

        $this->reconciliationService->computeDifference($this->tenant, $reconciliation);
    }

    public function test_transactions_outside_the_period_are_excluded_from_the_difference(): void
    {
        $this->importStatement("2026-07-15,Outside period,500.00,IN,,\n2026-08-01,Inside period,200.00,IN,,\n");

        $reconciliation = $this->open('1000.00', '1200.00', '2026-08-01', '2026-08-31');

        $difference = $this->reconciliationService->computeDifference($this->tenant, $reconciliation);

        $this->assertTrue($difference->isZero());
    }

    public function test_full_happy_path_lifecycle_end_to_end(): void
    {
        $this->importStatement("2026-08-01,Deposit,500.00,IN,,\n");

        $reconciliation = $this->open('1000.00', '1500.00');

        $reconciliation = $this->reconciliationService->startReview($this->tenant, $reconciliation->id());
        $this->assertSame(ReconciliationState::InReview, $reconciliation->state());

        $reconciliation = $this->reconciliationService->markBalanced($this->tenant, $reconciliation->id());
        $this->assertSame(ReconciliationState::Balanced, $reconciliation->state());

        $reconciliation = $this->reconciliationService->complete($this->tenant, $reconciliation->id());
        $this->assertSame(ReconciliationState::Completed, $reconciliation->state());
        $this->assertNotNull($reconciliation->completedAt());
    }

    public function test_marking_balanced_with_a_nonzero_difference_is_rejected(): void
    {
        $this->importStatement("2026-08-01,Deposit,500.00,IN,,\n");

        $reconciliation = $this->open('1000.00', '9999.00');
        $this->reconciliationService->startReview($this->tenant, $reconciliation->id());

        $this->expectException(ReconciliationNotBalancedException::class);

        $this->reconciliationService->markBalanced($this->tenant, $reconciliation->id());
    }

    public function test_completion_rechecks_the_live_difference_instead_of_trusting_stale_balanced_state(): void
    {
        $reconciliation = $this->open('1000.00', '1000.00');
        $this->reconciliationService->startReview($this->tenant, $reconciliation->id());
        $this->reconciliationService->markBalanced($this->tenant, $reconciliation->id());

        $this->importStatement("2026-08-15,Late statement row,10.00,IN,,LATE-001\n");

        try {
            $this->reconciliationService->complete($this->tenant, $reconciliation->id());
            $this->fail('Expected completion with a nonzero live difference to be rejected.');
        } catch (ReconciliationNotBalancedException) {
            $persisted = (new ReconciliationRepository(DB::connection('pgsql')))->getById($this->tenant, $reconciliation->id());

            $this->assertSame(ReconciliationState::Balanced, $persisted->state());
            $this->assertNull($persisted->completedAt());
        }
    }

    public function test_reopen_requires_a_non_empty_reason(): void
    {
        $reconciliation = $this->completedReconciliation();

        $this->expectException(ReconciliationReopenRequiresReasonException::class);

        $this->reconciliationService->reopen($this->tenant, $reconciliation->id(), '', ActorReference::of('actor-0001'));
    }

    public function test_reopen_persists_a_history_record_and_returns_to_draft(): void
    {
        $reconciliation = $this->completedReconciliation();

        $reopened = $this->reconciliationService->reopen($this->tenant, $reconciliation->id(), 'Found a missing transaction', ActorReference::of('actor-0001'));

        $this->assertSame(ReconciliationState::Draft, $reopened->state());
        $this->assertNull($reopened->completedAt());

        $reopenings = (new ReconciliationRepository(DB::connection('pgsql')))->findReopeningsFor($this->tenant, $reconciliation->id());
        $this->assertCount(1, $reopenings);
        $this->assertSame('Found a missing transaction', $reopenings[0]->reason());
    }

    public function test_reopening_twice_persists_two_history_records(): void
    {
        $reconciliation = $this->completedReconciliation();

        $this->reconciliationService->reopen($this->tenant, $reconciliation->id(), 'First reason', ActorReference::of('actor-0001'));
        $this->reconciliationService->startReview($this->tenant, $reconciliation->id());
        $this->reconciliationService->markBalanced($this->tenant, $reconciliation->id());
        $this->reconciliationService->complete($this->tenant, $reconciliation->id());
        $this->reconciliationService->reopen($this->tenant, $reconciliation->id(), 'Second reason', ActorReference::of('actor-0001'));

        $reopenings = (new ReconciliationRepository(DB::connection('pgsql')))->findReopeningsFor($this->tenant, $reconciliation->id());
        $this->assertCount(2, $reopenings);
    }

    public function test_a_forced_reopening_history_failure_rolls_back_the_state_change(): void
    {
        $reconciliation = $this->completedReconciliation();
        $connection = DB::connection('pgsql');
        $constraint = 'reconciliation_reopenings_forced_failure';

        $connection->statement(sprintf(
            'alter table %s add constraint %s check (reason <> %s) not valid',
            self::REOPENING_TABLE,
            $constraint,
            $connection->getPdo()->quote('force-failure'),
        ));

        try {
            try {
                $this->reconciliationService->reopen($this->tenant, $reconciliation->id(), 'force-failure', ActorReference::of('actor-0001'));
                $this->fail('Expected the forced reopening-history persistence failure to propagate.');
            } catch (\Throwable $exception) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }

            $persisted = (new ReconciliationRepository($connection))->getById($this->tenant, $reconciliation->id());

            $this->assertSame(ReconciliationState::Completed, $persisted->state());
            $this->assertNotNull($persisted->completedAt());
            $this->assertSame(0, $connection->table(self::REOPENING_TABLE)->count());
        } finally {
            $connection->statement(sprintf('alter table %s drop constraint if exists %s', self::REOPENING_TABLE, $constraint));
        }
    }

    /**
     * Proves the row lock at the Reconciliation lifecycle boundary with
     * genuine OS-process races. For every fixed state transition, exactly
     * one caller may advance the aggregate; the loser reloads the winner's
     * committed state and is rejected by the existing state machine.
     *
     * Reopen is included because it has a second authoritative write: the
     * winning state change and its reopening-history row must remain one
     * atomic outcome, never two histories for one Completed -> Draft edge.
     */
    public function test_concurrent_lifecycle_transitions_cannot_overwrite_or_skip_state(): void
    {
        $draft = $this->open('1000.00', '1000.00');
        $this->assertConcurrentTransition('start-review', $draft, ReconciliationState::InReview);

        $inReview = $this->open('1000.00', '1000.00');
        $inReview = $this->reconciliationService->startReview($this->tenant, $inReview->id());
        $this->assertConcurrentTransition('mark-balanced', $inReview, ReconciliationState::Balanced);

        $balanced = $this->open('1000.00', '1000.00');
        $this->reconciliationService->startReview($this->tenant, $balanced->id());
        $balanced = $this->reconciliationService->markBalanced($this->tenant, $balanced->id());
        $this->assertConcurrentTransition('complete', $balanced, ReconciliationState::Completed);

        $completed = $this->completedReconciliation();
        $this->assertConcurrentTransition('reopen', $completed, ReconciliationState::Draft);

        $reopenings = (new ReconciliationRepository(DB::connection('pgsql')))
            ->findReopeningsFor($this->tenant, $completed->id());

        $this->assertCount(1, $reopenings);
        $this->assertSame('Concurrent integrity proof', $reopenings[0]->reason());
    }

    private function completedReconciliation(): Reconciliation
    {
        $reconciliation = $this->open('1000.00', '1000.00');
        $this->reconciliationService->startReview($this->tenant, $reconciliation->id());
        $this->reconciliationService->markBalanced($this->tenant, $reconciliation->id());

        return $this->reconciliationService->complete($this->tenant, $reconciliation->id());
    }

    private function assertConcurrentTransition(string $action, Reconciliation $reconciliation, ReconciliationState $expectedState): void
    {
        [$resultA, $resultB] = $this->raceTransitionWorkers($action, $reconciliation);
        $outcomes = ['A' => $resultA, 'B' => $resultB];

        $succeeded = array_filter(
            $outcomes,
            static fn (array $result): bool => ($result['state'] ?? null) === $expectedState->name,
        );
        $rejected = array_filter(
            $outcomes,
            static fn (array $result): bool => ($result['exception'] ?? null) === InvalidReconciliationStateTransitionException::class,
        );

        $this->assertCount(1, $succeeded, sprintf(
            'Exactly one concurrent %s transition must succeed. Got: %s',
            $action,
            json_encode($outcomes),
        ));
        $this->assertCount(1, $rejected, sprintf(
            'The other concurrent %s transition must be safely rejected. Got: %s',
            $action,
            json_encode($outcomes),
        ));

        $persisted = (new ReconciliationRepository(DB::connection('pgsql')))
            ->getById($this->tenant, $reconciliation->id());

        $this->assertSame($expectedState, $persisted->state());
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function raceTransitionWorkers(string $action, Reconciliation $reconciliation): array
    {
        $temporaryDirectory = sys_get_temp_dir();
        $readyA = tempnam($temporaryDirectory, 'recon_ready_a_');
        $readyB = tempnam($temporaryDirectory, 'recon_ready_b_');
        $goFile = tempnam($temporaryDirectory, 'recon_go_');
        $resultA = tempnam($temporaryDirectory, 'recon_result_a_');
        $resultB = tempnam($temporaryDirectory, 'recon_result_b_');

        foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $file) {
            unlink($file);
        }

        $workerScript = base_path('tests/bin/concurrent_reconciliation_transition_worker.php');
        $arguments = [$this->tenant->toString(), $reconciliation->id()->toString(), $action];

        $processA = proc_open(
            ['php', $workerScript, ...$arguments, 'actor-concurrent-a', $readyA, $goFile, $resultA],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $processB = proc_open(
            ['php', $workerScript, ...$arguments, 'actor-concurrent-b', $readyB, $goFile, $resultB],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB,
        );

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        try {
            $deadline = microtime(true) + 5.0;
            while (! (file_exists($readyA) && file_exists($readyB))) {
                if (microtime(true) > $deadline) {
                    $this->fail('Timed out waiting for both Reconciliation workers to signal ready.');
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
                    $this->fail('Timed out waiting for both Reconciliation worker results.');
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

    private function importStatement(string $rows): void
    {
        $csv = "date,description,amount,direction,balance,reference\n".$rows;
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);
    }

    private function open(string $opening, string $closing, string $periodStart = '2026-08-01', string $periodEnd = '2026-08-31'): Reconciliation
    {
        return $this->reconciliationService->open(
            $this->tenant,
            BankAccountId::of('bank-account-0001'),
            new \DateTimeImmutable($periodStart),
            new \DateTimeImmutable($periodEnd),
            Money::fromDecimalString($opening, $this->myr),
            Money::fromDecimalString($closing, $this->myr),
        );
    }

    private function insertAccount(string $accountId, string $accountType): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $this->tenant->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($accountId), 0, 10),
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

        $requiredTables = [
            self::ACCOUNT_TABLE => 'database/migrations/2026_09_04_030000_create_accounts_table.php',
            'bank_accounts' => 'database/migrations/2026_09_07_090000_create_bank_accounts_table.php',
            'bank_statement_import_batches' => 'database/migrations/2026_09_07_100000_create_bank_statement_import_batches_table.php',
            'bank_transactions' => 'database/migrations/2026_09_07_110000_create_bank_transactions_table.php',
            self::RECONCILIATION_TABLE => 'database/migrations/2026_09_07_130000_create_reconciliations_table.php',
            self::REOPENING_TABLE => 'database/migrations/2026_09_07_140000_create_reconciliation_reopenings_table.php',
        ];

        foreach ($requiredTables as $table => $migrationPath) {
            if (! Schema::connection('pgsql')->hasTable($table)) {
                self::forceCleanMigration($migrationPath, []);
            }
        }

        self::$migrated = true;
    }

    /**
     * Drops the given tables directly and clears their migration's
     * tracking row (if any), so a subsequent `migrate` call for that
     * path is guaranteed to actually (re)run it — a bare
     * `Artisan::call('migrate', ...)` silently no-ops when the
     * `migrations` tracking table still believes a path is already
     * applied, even though another test class's own `dropIfExists()`
     * (never clearing that tracking row, by this codebase's own
     * established "not that test's concern" convention) removed the
     * table itself.
     *
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
