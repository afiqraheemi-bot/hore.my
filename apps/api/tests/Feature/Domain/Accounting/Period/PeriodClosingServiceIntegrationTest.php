<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Accounting\Period;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Period\Exception\InvalidRetainedEarningsAccountTypeException;
use App\Domain\Accounting\Period\Exception\NothingToCloseException;
use App\Domain\Accounting\Period\Exception\PeriodAlreadyClosedException;
use App\Domain\Accounting\Period\PeriodClosingCommand;
use App\Domain\Accounting\Period\PeriodClosingService;
use App\Domain\Accounting\Period\PeriodClosingToPostingCommandTranslator;
use App\Domain\Accounting\Period\RetainedEarningsAccountTypeValidator;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\Exception\RejectedClosedPeriodPostingException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\PostingCommandIdempotencyResolver;
use App\Domain\Accounting\Posting\PostingCommandJournalExecutor;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\PostingCommandPeriodLockValidator;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Accounting\Reporting\AccountBalance;
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
use App\Infrastructure\Accounting\Reporting\AccountBalanceAggregator;
use App\Infrastructure\Accounting\Reporting\BalanceSheetQuery;
use App\Infrastructure\Accounting\Reporting\ProfitAndLossQuery;
use App\Infrastructure\Accounting\Reporting\TrialBalanceQuery;
use App\Infrastructure\Transactions\Expense\ExpenseRepository;
use App\Infrastructure\Transactions\Income\IncomeRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for M13 Period Management (AETS-014) —
 * exercised against a real PostgreSQL instance, with real Expense (M7)
 * and Income (M9) postings as the golden dataset, never stubs.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason (mirrors the established convention).
 */
final class PeriodClosingServiceIntegrationTest extends TestCase
{
    private const TABLES_TO_CLEAN = [
        'period_closures',
        'posting_idempotency_keys',
        'posting_source_fingerprints',
        'audit_events',
        'journal_evidence_links',
        'expenses',
        'incomes',
        'transfers',
        'owner_equity_transactions',
        'reconciliation_reopenings',
        'matches',
        'bank_transactions',
        'reconciliations',
        'bank_statement_import_batches',
        'bank_accounts',
        'journal_lines',
        'journals',
        'accounts',
        'tenants',
        'users',
    ];

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private ExpenseRecordingService $expenseService;

    private IncomeRecordingService $incomeService;

    private PeriodClosingService $periodClosingService;

    private TrialBalanceQuery $trialBalanceQuery;

    private ProfitAndLossQuery $profitAndLossQuery;

    private BalanceSheetQuery $balanceSheetQuery;

    private TenantId $tenantA;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        foreach (self::TABLES_TO_CLEAN as $table) {
            DB::connection('pgsql')->table($table)->delete();
        }

        $connection = DB::connection('pgsql');
        $this->expenseService = $this->buildExpenseService($connection);
        $this->incomeService = $this->buildIncomeService($connection);
        $this->periodClosingService = $this->buildPeriodClosingService($connection);

        $aggregator = new AccountBalanceAggregator($connection);
        $this->trialBalanceQuery = new TrialBalanceQuery($aggregator);
        $this->profitAndLossQuery = new ProfitAndLossQuery($aggregator);
        $this->balanceSheetQuery = new BalanceSheetQuery($aggregator, $this->profitAndLossQuery);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-cash', 'Asset');
        $this->insertAccount($this->tenantA, 'account-office-supplies', 'Expense');
        $this->insertAccount($this->tenantA, 'account-sales-revenue', 'Revenue');
        $this->insertAccount($this->tenantA, 'account-retained-earnings', 'Equity');
    }

    protected function tearDown(): void
    {
        if (self::$skipReason === null) {
            foreach (self::TABLES_TO_CLEAN as $table) {
                DB::connection('pgsql')->table($table)->delete();
            }
        }

        parent::tearDown();
    }

    // --- Golden path: close a profitable period --------------------------

    public function test_closing_a_profitable_period_zeroes_revenue_and_expense_and_balances_via_retained_earnings(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();

        $result = $this->periodClosingService->close($this->makeClosingCommand());

        $this->assertTrue($result->isNewlyClosed());
        $this->assertSame('2026-08-31', $result->closure()->closedThroughDate()->format('Y-m-d'));

        // Trial Balance as of the closing date: Revenue/Expense now
        // zero (closed), Cash and Retained Earnings carry the effect.
        $trialBalance = $this->trialBalanceQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-08-31'));
        $this->assertTrue($trialBalance->isBalanced());

        $revenueLine = $this->findLine($trialBalance->lines(), 'account-sales-revenue');
        $this->assertTrue($revenueLine->netBalance()->isZero());

        $expenseLine = $this->findLine($trialBalance->lines(), 'account-office-supplies');
        $this->assertTrue($expenseLine->netBalance()->isZero());

        $retainedEarningsLine = $this->findLine($trialBalance->lines(), 'account-retained-earnings');
        $this->assertSame('150.00', $retainedEarningsLine->netBalance()->amount()->toDecimalString());

        // Balance Sheet still balances — Assets (Cash 150.00) equals
        // Equity (Retained Earnings 150.00, no more "unclosed books"
        // cumulative net income line needed for this closed range).
        $balanceSheet = $this->balanceSheetQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-08-31'));
        $this->assertTrue($balanceSheet->isBalanced());
        $this->assertSame('150.00', $balanceSheet->totalAssets()->toDecimalString());
    }

    public function test_closing_a_period_with_a_loss_debits_retained_earnings(): void
    {
        $this->expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-0001'),
            JournalId::of('journal-expense-0001'),
            IdempotencyKey::of('key-expense-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('200.00', $this->myr),
            new \DateTimeImmutable('2026-08-10'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-cash'),
            'Office supplies',
            null,
        ));

        $result = $this->periodClosingService->close($this->makeClosingCommand());

        $trialBalance = $this->trialBalanceQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-08-31'));
        $retainedEarningsLine = $this->findLine($trialBalance->lines(), 'account-retained-earnings');

        $this->assertTrue($result->isNewlyClosed());
        $this->assertSame('200.00', $retainedEarningsLine->netBalance()->amount()->toDecimalString());
        $this->assertTrue($trialBalance->isBalanced());
    }

    // --- Idempotency and conflict -------------------------------------------

    public function test_closing_the_same_period_twice_with_the_same_key_replays(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();

        $command = $this->makeClosingCommand();
        $first = $this->periodClosingService->close($command);
        $second = $this->periodClosingService->close($command);

        $this->assertTrue($first->isNewlyClosed());
        $this->assertTrue($second->isReplay());
        $this->assertTrue($first->closure()->closingJournalId()->equals($second->closure()->closingJournalId()));
        $this->assertSame(1, DB::connection('pgsql')->table('period_closures')->count());
    }

    public function test_closing_backwards_is_rejected(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();

        $this->periodClosingService->close($this->makeClosingCommand());

        $this->expectException(PeriodAlreadyClosedException::class);

        $this->periodClosingService->close($this->makeClosingCommand(
            idempotencyKey: 'key-close-different-0002',
            journalId: 'journal-closing-0002',
            closedThroughDate: '2026-08-15',
        ));
    }

    public function test_nothing_to_close_is_rejected(): void
    {
        $this->expectException(NothingToCloseException::class);

        $this->periodClosingService->close($this->makeClosingCommand());
    }

    public function test_a_non_equity_retained_earnings_account_is_rejected(): void
    {
        $this->postGoldenExpense();

        $this->expectException(InvalidRetainedEarningsAccountTypeException::class);

        $this->periodClosingService->close($this->makeClosingCommand(retainedEarningsAccountId: 'account-cash'));
    }

    // --- Enforcement: no ordinary posting into a closed period --------------

    public function test_an_ordinary_posting_into_a_closed_period_is_rejected(): void
    {
        $this->postGoldenExpense();
        $this->periodClosingService->close($this->makeClosingCommand());

        $this->expectException(RejectedClosedPeriodPostingException::class);

        $this->expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-late-0001'),
            JournalId::of('journal-expense-late-0001'),
            IdempotencyKey::of('key-expense-late-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('10.00', $this->myr),
            new \DateTimeImmutable('2026-08-20'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-cash'),
            'Backdated into a closed period',
            null,
        ));
    }

    /**
     * Proves AETS-009 §8/§15's own deferred question — how the
     * "unclosed books" Cumulative Net Income line behaves once a real
     * closing exists — resolves automatically, with no change needed
     * to {@see BalanceSheetQuery}:
     * since the closing Journal's own zeroing lines are Posted Journal
     * Lines like any other, `ProfitAndLossQuery::forPeriod([inception,
     * asOfDate])` already nets August's original activity against
     * August's own closing entries to zero, leaving only September's
     * genuinely new activity in the "cumulative net income" line —
     * Retained Earnings (a real posted balance) and the residual
     * cumulative line never double-count the same profit.
     */
    public function test_the_unclosed_books_convention_correctly_shows_only_post_closing_activity(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();
        $this->periodClosingService->close($this->makeClosingCommand());

        $this->incomeService->record(new RecordIncomeCommand(
            IncomeId::of('income-september-0001'),
            JournalId::of('journal-income-september-0001'),
            IdempotencyKey::of('key-income-september-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('30.00', $this->myr),
            new \DateTimeImmutable('2026-09-10'),
            AccountId::of('account-sales-revenue'),
            AccountId::of('account-cash'),
            'September consulting revenue',
            null,
        ));

        $balanceSheet = $this->balanceSheetQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-09-30'));

        $this->assertTrue($balanceSheet->isBalanced());
        // Cash: 150.00 (August) + 30.00 (September) = 180.00.
        $this->assertSame('180.00', $balanceSheet->totalAssets()->toDecimalString());
        // Retained Earnings holds exactly August's closed profit.
        $retainedEarningsLine = $this->findLine(
            $this->trialBalanceQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-09-30'))->lines(),
            'account-retained-earnings',
        );
        $this->assertSame('150.00', $retainedEarningsLine->netBalance()->amount()->toDecimalString());
        // The residual "unclosed books" cumulative line reflects only
        // September's own new activity (30.00), never August's again.
        $this->assertSame('30.00', $balanceSheet->cumulativeNetIncome()->amount()->toDecimalString());
    }

    public function test_a_posting_dated_after_the_closed_period_is_accepted(): void
    {
        $this->postGoldenExpense();
        $this->periodClosingService->close($this->makeClosingCommand());

        $result = $this->expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-september-0001'),
            JournalId::of('journal-expense-september-0001'),
            IdempotencyKey::of('key-expense-september-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('20.00', $this->myr),
            new \DateTimeImmutable('2026-09-01'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-cash'),
            'A new month, correctly accepted',
            null,
        ));

        $this->assertTrue($result->isNewlyRecorded());
    }

    // --- Fixtures and helpers ------------------------------------------

    private function postGoldenExpense(): mixed
    {
        return $this->expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-0001'),
            JournalId::of('journal-expense-0001'),
            IdempotencyKey::of('key-expense-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('50.00', $this->myr),
            new \DateTimeImmutable('2026-08-10'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-cash'),
            'Office supplies',
            null,
        ));
    }

    private function postGoldenIncome(): mixed
    {
        return $this->incomeService->record(new RecordIncomeCommand(
            IncomeId::of('income-0001'),
            JournalId::of('journal-income-0001'),
            IdempotencyKey::of('key-income-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('200.00', $this->myr),
            new \DateTimeImmutable('2026-08-15'),
            AccountId::of('account-sales-revenue'),
            AccountId::of('account-cash'),
            'Consulting revenue',
            null,
        ));
    }

    private function makeClosingCommand(
        string $idempotencyKey = 'key-close-0001',
        string $journalId = 'journal-closing-0001',
        string $closedThroughDate = '2026-08-31',
        string $retainedEarningsAccountId = 'account-retained-earnings',
    ): PeriodClosingCommand {
        return new PeriodClosingCommand(
            $this->tenantA,
            IdempotencyKey::of($idempotencyKey),
            ActorReference::of('actor-0001'),
            JournalId::of($journalId),
            new \DateTimeImmutable($closedThroughDate),
            AccountId::of($retainedEarningsAccountId),
        );
    }

    /**
     * @param  list<AccountBalance>  $lines
     */
    private function findLine(array $lines, string $accountId): AccountBalance
    {
        foreach ($lines as $line) {
            if ($line->accountId()->equals(AccountId::of($accountId))) {
                return $line;
            }
        }

        $this->fail(sprintf('No Trial Balance line found for Account "%s".', $accountId));
    }

    private function insertAccount(TenantId $tenantId, string $accountId, string $accountType): void
    {
        DB::connection('pgsql')->table('accounts')->insert([
            'tenant_id' => $tenantId->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId->toString().$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => $accountType,
            'account_origin' => 'UserCreated',
            'active' => true,
            'posting_eligible' => true,
            'parent_id' => null,
        ]);
    }

    private function buildExpenseService(ConnectionInterface $connection): ExpenseRecordingService
    {
        $accountRepository = new AccountRepository($connection);

        return new ExpenseRecordingService(
            $connection,
            new ExpenseAccountTypeValidator($accountRepository),
            new ExpenseToPostingCommandTranslator,
            $this->buildPostingExecutor($connection),
            new ExpenseRepository($connection),
        );
    }

    private function buildIncomeService(ConnectionInterface $connection): IncomeRecordingService
    {
        $accountRepository = new AccountRepository($connection);

        return new IncomeRecordingService(
            $connection,
            new IncomeAccountTypeValidator($accountRepository),
            new IncomeToPostingCommandTranslator,
            $this->buildPostingExecutor($connection),
            new IncomeRepository($connection),
        );
    }

    private function buildPeriodClosingService(ConnectionInterface $connection): PeriodClosingService
    {
        return new PeriodClosingService(
            $connection,
            new RetainedEarningsAccountTypeValidator(new AccountRepository($connection)),
            new PeriodClosureRepository($connection),
            new AccountBalanceAggregator($connection),
            new PeriodClosingToPostingCommandTranslator,
            $this->buildPostingExecutor($connection),
        );
    }

    private function buildPostingExecutor(ConnectionInterface $connection): PostingCommandTransactionalExecutor
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

        $requiredTables = [...self::TABLES_TO_CLEAN, 'password_reset_tokens'];
        $missingATable = false;

        foreach ($requiredTables as $table) {
            if (! Schema::connection('pgsql')->hasTable($table)) {
                $missingATable = true;

                break;
            }
        }

        if ($missingATable) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql', '--force' => true]);
        }

        self::$migrated = true;
    }
}
