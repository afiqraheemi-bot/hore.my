<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Banking;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\PostingCommandIdempotencyResolver;
use App\Domain\Accounting\Posting\PostingCommandJournalExecutor;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\PostingCommandPeriodLockValidator;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Banking\BankAccount;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankStatementImportService;
use App\Domain\Banking\BankTransactionMatchSuggester;
use App\Domain\Banking\CsvBankStatementParser;
use App\Domain\Banking\Exception\InvalidReconciliationStateTransitionException;
use App\Domain\Banking\Exception\ReconciliationComputationExceedsSupportedRangeException;
use App\Domain\Banking\Exception\ReconciliationHasUnmatchedTransactionsException;
use App\Domain\Banking\Exception\ReconciliationNotBalancedException;
use App\Domain\Banking\Exception\ReconciliationPeriodOverlapException;
use App\Domain\Banking\Exception\ReconciliationReopenRequiresReasonException;
use App\Domain\Banking\MatchingService;
use App\Domain\Banking\Reconciliation;
use App\Domain\Banking\ReconciliationService;
use App\Domain\Banking\ReconciliationState;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\ExpenseAccountTypeValidator;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Domain\Transactions\Expense\ExpenseToPostingCommandTranslator;
use App\Domain\Transactions\Expense\RecordExpenseCommand;
use App\Domain\Transactions\Income\IncomeAccountTypeValidator;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Domain\Transactions\Income\IncomeToPostingCommandTranslator;
use App\Domain\Transactions\Income\RecordIncomeCommand;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Period\PeriodClosureRepository;
use App\Infrastructure\Accounting\Posting\JournalEvidenceLinkRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use App\Infrastructure\Banking\BankAccountRepository;
use App\Infrastructure\Banking\BankTransactionRepository;
use App\Infrastructure\Banking\ImportBatchRepository;
use App\Infrastructure\Banking\MatchRepository;
use App\Infrastructure\Banking\ReconciliationRepository;
use App\Infrastructure\Transactions\Expense\ExpenseRepository;
use App\Infrastructure\Transactions\Income\IncomeRepository;
use App\Infrastructure\Transactions\OwnerEquity\OwnerEquityTransactionRepository;
use App\Infrastructure\Transactions\Transfer\TransferRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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

        if (! Schema::connection('pgsql')->hasTable('matches')) {
            self::forceCleanMigration('database/migrations/2026_09_07_120000_create_matches_table.php', []);
        }

        if (! Schema::connection('pgsql')->hasTable('expenses')) {
            self::forceCleanMigration('database/migrations/2026_09_06_220000_create_expenses_table.php', []);
        }

        if (! Schema::connection('pgsql')->hasTable('incomes')) {
            self::forceCleanMigration('database/migrations/2026_09_06_235000_create_incomes_table.php', []);
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
            new MatchRepository($connection),
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

    /**
     * BNK-015 (AETS-008 §12.4): overlapping periods for the same Bank
     * Account are rejected, regardless of the existing Reconciliation's
     * lifecycle state — including `Completed`.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function overlappingPeriodProvider(): iterable
    {
        yield 'identical period' => ['2026-08-01', '2026-08-31', '2026-08-01', '2026-08-31'];
        yield 'new period starts inside existing' => ['2026-08-01', '2026-08-31', '2026-08-15', '2026-09-15'];
        yield 'new period ends inside existing' => ['2026-08-01', '2026-08-31', '2026-07-15', '2026-08-15'];
        yield 'new period wholly contains existing' => ['2026-08-01', '2026-08-31', '2026-07-01', '2026-09-30'];
        yield 'new period wholly inside existing' => ['2026-08-01', '2026-08-31', '2026-08-10', '2026-08-20'];
        yield 'single-day touch at the boundary' => ['2026-08-01', '2026-08-31', '2026-08-31', '2026-09-15'];
    }

    #[DataProvider('overlappingPeriodProvider')]
    public function test_opening_an_overlapping_period_is_rejected(string $existingStart, string $existingEnd, string $newStart, string $newEnd): void
    {
        $this->open('1000.00', '1000.00', $existingStart, $existingEnd);

        $this->expectException(ReconciliationPeriodOverlapException::class);

        $this->open('1000.00', '1000.00', $newStart, $newEnd);
    }

    /**
     * BNK-015 still allows adjacent, genuinely non-overlapping periods —
     * this is a period-overlap rule, not a one-Reconciliation-ever
     * limit.
     */
    public function test_opening_an_adjacent_non_overlapping_period_is_allowed(): void
    {
        $this->open('1000.00', '1000.00', '2026-08-01', '2026-08-31');

        $second = $this->open('1000.00', '1000.00', '2026-09-01', '2026-09-30');

        $this->assertSame(ReconciliationState::Draft, $second->state());
        $this->assertSame(2, DB::connection('pgsql')->table(self::RECONCILIATION_TABLE)->count());
    }

    /**
     * BNK-015 (AETS-008 §12.4): overlap is rejected even against an
     * existing Reconciliation that is `Completed` — the rule is about
     * the *period*, not the lifecycle state.
     */
    public function test_opening_a_period_overlapping_a_completed_reconciliation_is_rejected(): void
    {
        $completed = $this->open('1000.00', '1000.00');
        $this->reconciliationService->startReview($this->tenant, $completed->id());
        $this->reconciliationService->markBalanced($this->tenant, $completed->id());
        $this->reconciliationService->complete($this->tenant, $completed->id());

        $this->expectException(ReconciliationPeriodOverlapException::class);

        $this->open('1000.00', '1000.00', '2026-08-15', '2026-09-15');
    }

    /**
     * BNK-015 (AETS-008 §12.4): a genuine two-process race between two
     * overlapping `open()` calls for the same Bank Account must let
     * exactly one succeed — the BankAccount row lock must serialize the
     * overlap check, not let both read a stale, empty existing-period
     * list.
     */
    public function test_concurrent_opening_of_overlapping_periods_lets_only_one_succeed(): void
    {
        [$resultA, $resultB] = $this->raceOpenWorkers(
            ['2026-08-01', '2026-08-31'],
            ['2026-08-15', '2026-09-15'],
        );
        $outcomes = ['A' => $resultA, 'B' => $resultB];

        $succeeded = array_filter($outcomes, static fn (array $r): bool => ($r['status'] ?? null) === 'success');
        $rejected = array_filter($outcomes, static fn (array $r): bool => ($r['exception'] ?? null) === ReconciliationPeriodOverlapException::class);

        $this->assertCount(1, $succeeded, sprintf('Exactly one concurrent open() must succeed. Got: %s', json_encode($outcomes)));
        $this->assertCount(1, $rejected, sprintf('The other must be rejected as overlapping. Got: %s', json_encode($outcomes)));
        $this->assertSame(1, DB::connection('pgsql')->table(self::RECONCILIATION_TABLE)->count());
    }

    /**
     * @param  array{0: string, 1: string}  $periodA
     * @param  array{0: string, 1: string}  $periodB
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function raceOpenWorkers(array $periodA, array $periodB): array
    {
        $temporaryDirectory = sys_get_temp_dir();
        $readyA = tempnam($temporaryDirectory, 'recon_open_ready_a_');
        $readyB = tempnam($temporaryDirectory, 'recon_open_ready_b_');
        $goFile = tempnam($temporaryDirectory, 'recon_open_go_');
        $resultA = tempnam($temporaryDirectory, 'recon_open_result_a_');
        $resultB = tempnam($temporaryDirectory, 'recon_open_result_b_');

        foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $file) {
            unlink($file);
        }

        $workerScript = base_path('tests/bin/concurrent_reconciliation_open_worker.php');

        $processA = proc_open(
            ['php', $workerScript, $this->tenant->toString(), 'bank-account-0001', ...$periodA, $readyA, $goFile, $resultA],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $processB = proc_open(
            ['php', $workerScript, $this->tenant->toString(), 'bank-account-0001', ...$periodB, $readyB, $goFile, $resultB],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB,
        );

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        try {
            $deadline = microtime(true) + 5.0;
            while (! (file_exists($readyA) && file_exists($readyB))) {
                if (microtime(true) > $deadline) {
                    $this->fail('Timed out waiting for both Reconciliation-open workers to signal ready.');
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
                    $this->fail('Timed out waiting for both Reconciliation-open worker results.');
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
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
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
        // No imported activity in-period: an empty period is trivially
        // both zero-difference (BNK-009) and fully matched (BNK-016,
        // vacuously — there is nothing to match). Matched completion is
        // separately proven by test_completion_succeeds_once_every_in_period_transaction_is_matched().
        $reconciliation = $this->open('1000.00', '1000.00');

        $reconciliation = $this->reconciliationService->startReview($this->tenant, $reconciliation->id());
        $this->assertSame(ReconciliationState::InReview, $reconciliation->state());

        $reconciliation = $this->reconciliationService->markBalanced($this->tenant, $reconciliation->id());
        $this->assertSame(ReconciliationState::Balanced, $reconciliation->state());

        $reconciliation = $this->reconciliationService->complete($this->tenant, $reconciliation->id());
        $this->assertSame(ReconciliationState::Completed, $reconciliation->state());
        $this->assertNotNull($reconciliation->completedAt());
    }

    /**
     * BNK-016 (AETS-008 §12.1): a zero arithmetic difference alone is
     * not enough — an unmatched in-period BankTransaction blocks
     * completion even though the imported activity nets to exactly the
     * stated closing balance.
     */
    public function test_completion_is_rejected_while_any_in_period_transaction_is_unmatched(): void
    {
        $this->importStatement("2026-08-01,Deposit,500.00,IN,,\n2026-08-15,Withdrawal,200.00,OUT,,\n");

        $reconciliation = $this->open('1000.00', '1300.00');
        $this->reconciliationService->startReview($this->tenant, $reconciliation->id());
        $this->reconciliationService->markBalanced($this->tenant, $reconciliation->id());

        $this->expectException(ReconciliationHasUnmatchedTransactionsException::class);

        $this->reconciliationService->complete($this->tenant, $reconciliation->id());
    }

    /**
     * BNK-016 (AETS-008 §12.1): once every in-period BankTransaction has
     * a confirmed Match, completion succeeds — proving the check is not
     * simply an unconditional block.
     */
    public function test_completion_succeeds_once_every_in_period_transaction_is_matched(): void
    {
        $connection = DB::connection('pgsql');
        $this->insertAccount('account-office-supplies', 'Expense');

        $expenseService = $this->buildExpenseServiceForMatching($connection);
        $matchingService = $this->buildMatchingServiceForReconciliation($connection);

        $expenseResult = $expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-recon-0001'),
            JournalId::of('journal-recon-expense-0001'),
            IdempotencyKey::of('key-recon-expense-0001'),
            $this->tenant,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('200.00', $this->myr),
            new \DateTimeImmutable('2026-08-15'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-bank'),
            'Office supplies',
            null,
        ));

        $this->importStatement("2026-08-01,Deposit,500.00,IN,,\n2026-08-15,Withdrawal,200.00,OUT,,\n");

        $reconciliation = $this->open('1000.00', '1300.00');

        $candidates = $matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));
        foreach ($candidates as $candidate) {
            if ($candidate->journalId()->equals($expenseResult->expense()->journalId())) {
                $matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));
            }
        }

        // The $500 Deposit still has no matching Income posted, so
        // completion must still be rejected.
        $this->reconciliationService->startReview($this->tenant, $reconciliation->id());
        $this->reconciliationService->markBalanced($this->tenant, $reconciliation->id());

        try {
            $this->reconciliationService->complete($this->tenant, $reconciliation->id());
            $this->fail('Expected completion to still be rejected while the Deposit remains unmatched.');
        } catch (ReconciliationHasUnmatchedTransactionsException) {
            // expected
        }

        $this->insertAccount('account-revenue', 'Revenue');

        $incomeResult = $this->buildIncomeServiceForMatching($connection)->record(new RecordIncomeCommand(
            IncomeId::of('income-recon-0001'),
            JournalId::of('journal-recon-income-0001'),
            IdempotencyKey::of('key-recon-income-0001'),
            $this->tenant,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('500.00', $this->myr),
            new \DateTimeImmutable('2026-08-01'),
            AccountId::of('account-revenue'),
            AccountId::of('account-bank'),
            'Consulting revenue',
            null,
        ));

        foreach ($matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001')) as $candidate) {
            if ($candidate->journalId()->equals($incomeResult->income()->journalId())) {
                $matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));
            }
        }

        $completed = $this->reconciliationService->complete($this->tenant, $reconciliation->id());
        $this->assertSame(ReconciliationState::Completed, $completed->state());
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
        // Distinct, non-overlapping periods per Reconciliation opened
        // here (BNK-015) — this test exercises four independent
        // lifecycle races, not four transitions of the same period.
        $draft = $this->open('1000.00', '1000.00', '2026-01-01', '2026-01-31');
        $this->assertConcurrentTransition('start-review', $draft, ReconciliationState::InReview);

        $inReview = $this->open('1000.00', '1000.00', '2026-02-01', '2026-02-28');
        $inReview = $this->reconciliationService->startReview($this->tenant, $inReview->id());
        $this->assertConcurrentTransition('mark-balanced', $inReview, ReconciliationState::Balanced);

        $balanced = $this->open('1000.00', '1000.00', '2026-03-01', '2026-03-31');
        $this->reconciliationService->startReview($this->tenant, $balanced->id());
        $balanced = $this->reconciliationService->markBalanced($this->tenant, $balanced->id());
        $this->assertConcurrentTransition('complete', $balanced, ReconciliationState::Completed);

        $completed = $this->completedReconciliation('2026-04-01', '2026-04-30');
        $this->assertConcurrentTransition('reopen', $completed, ReconciliationState::Draft);

        $reopenings = (new ReconciliationRepository(DB::connection('pgsql')))
            ->findReopeningsFor($this->tenant, $completed->id());

        $this->assertCount(1, $reopenings);
        $this->assertSame('Concurrent integrity proof', $reopenings[0]->reason());
    }

    private function completedReconciliation(string $periodStart = '2026-08-01', string $periodEnd = '2026-08-31'): Reconciliation
    {
        $reconciliation = $this->open('1000.00', '1000.00', $periodStart, $periodEnd);
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

    private function buildExpenseServiceForMatching(ConnectionInterface $connection): ExpenseRecordingService
    {
        $postingExecutor = $this->buildPostingExecutorForMatching($connection);

        return new ExpenseRecordingService(
            $connection,
            new ExpenseAccountTypeValidator(new AccountRepository($connection)),
            new ExpenseToPostingCommandTranslator,
            $postingExecutor,
            new ExpenseRepository($connection),
        );
    }

    private function buildIncomeServiceForMatching(ConnectionInterface $connection): IncomeRecordingService
    {
        $postingExecutor = $this->buildPostingExecutorForMatching($connection);

        return new IncomeRecordingService(
            $connection,
            new IncomeAccountTypeValidator(new AccountRepository($connection)),
            new IncomeToPostingCommandTranslator,
            $postingExecutor,
            new IncomeRepository($connection),
        );
    }

    private function buildPostingExecutorForMatching(ConnectionInterface $connection): PostingCommandTransactionalExecutor
    {
        $journalRepository = new JournalRepository($connection);
        $accountRepository = new AccountRepository($connection);
        $idempotencyRepository = new PostingIdempotencyRepository($connection);

        $journalExecutor = new PostingCommandJournalExecutor(
            new PostingCommandJournalStateResolver($journalRepository),
            new PostingCommandAccountValidator($accountRepository),
            new PostingCommandPeriodLockValidator(new PeriodClosureRepository($connection)),
            new PostingCommandExistingDraftLineValidator,
            new DraftJournalAssembler,
            $journalRepository,
        );

        $idempotencyResolver = new PostingCommandIdempotencyResolver(
            $idempotencyRepository,
            $journalRepository,
            new PostingCommandLogicalEquivalence,
        );

        return new PostingCommandTransactionalExecutor(
            $connection,
            $idempotencyResolver,
            $journalExecutor,
            $idempotencyRepository,
            new AuditEventRepository($connection),
            new JournalEvidenceLinkRepository($connection),
        );
    }

    private function buildMatchingServiceForReconciliation(ConnectionInterface $connection): MatchingService
    {
        $suggester = new BankTransactionMatchSuggester(
            $connection,
            new ExpenseRepository($connection),
            new IncomeRepository($connection),
            new TransferRepository($connection),
            new OwnerEquityTransactionRepository($connection),
        );

        return new MatchingService(
            $connection,
            new BankAccountRepository($connection),
            new BankTransactionRepository($connection),
            $suggester,
            new MatchRepository($connection),
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
