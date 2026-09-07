<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\JournalCorrectionCandidateAssembler;
use App\Domain\Accounting\Posting\JournalCorrectionIdempotencyResolver;
use App\Domain\Accounting\Posting\JournalCorrectionLogicalEquivalence;
use App\Domain\Accounting\Posting\JournalCorrectionTransactionalExecutor;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\PostingCommandIdempotencyResolver;
use App\Domain\Accounting\Posting\PostingCommandJournalExecutor;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\PostingCommandPeriodLockValidator;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Accounting\Posting\ReverseJournalCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\EvidenceIndexEntry;
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
use App\Infrastructure\Accounting\Reporting\EvidenceIndexQuery;
use App\Infrastructure\Accounting\Reporting\GeneralLedgerQuery;
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
 * Integration-level proof for M10 Financial Reporting (AETS-009) —
 * {@see TrialBalanceQuery}, {@see ProfitAndLossQuery},
 * {@see BalanceSheetQuery}, {@see GeneralLedgerQuery}, and
 * {@see EvidenceIndexQuery} — exercised against a real PostgreSQL
 * instance, with real Expense (M7) and Income (M9) postings as the
 * golden dataset, never stubs.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason (mirrors the established convention, e.g.
 * `ExpenseRecordingServiceIntegrationTest`).
 */
final class ReportingQueriesIntegrationTest extends TestCase
{
    private const EXPENSE_TABLE = 'expenses';

    private const INCOME_TABLE = 'incomes';

    private const IDEMPOTENCY_TABLE = 'posting_idempotency_keys';

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const EVIDENCE_LINK_TABLE = 'journal_evidence_links';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const EXPENSE_MIGRATION_PATH = 'database/migrations/2026_09_06_220000_create_expenses_table.php';

    private const INCOME_MIGRATION_PATH = 'database/migrations/2026_09_06_235000_create_incomes_table.php';

    private const IDEMPOTENCY_MIGRATION_PATH = 'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php';

    private const AUDIT_EVENT_MIGRATION_PATH = 'database/migrations/2026_09_06_200000_create_audit_events_table.php';

    private const EVIDENCE_LINK_MIGRATION_PATH = 'database/migrations/2026_09_06_210000_create_journal_evidence_links_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private ExpenseRecordingService $expenseService;

    private IncomeRecordingService $incomeService;

    private JournalRepository $journalRepository;

    private TrialBalanceQuery $trialBalanceQuery;

    private ProfitAndLossQuery $profitAndLossQuery;

    private BalanceSheetQuery $balanceSheetQuery;

    private GeneralLedgerQuery $generalLedgerQuery;

    private EvidenceIndexQuery $evidenceIndexQuery;

    private JournalCorrectionTransactionalExecutor $correctionExecutor;

    private TenantId $tenantA;

    private TenantId $tenantB;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        DB::connection('pgsql')->table(self::EXPENSE_TABLE)->delete();
        DB::connection('pgsql')->table(self::INCOME_TABLE)->delete();
        DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->delete();
        DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->delete();
        DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->delete();
        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        foreach (['reconciliation_reopenings', 'matches', 'bank_transactions', 'reconciliations', 'bank_statement_import_batches', 'bank_accounts'] as $bankingTable) {
            if (Schema::connection('pgsql')->hasTable($bankingTable)) {
                DB::connection('pgsql')->table($bankingTable)->delete();
            }
        }
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $connection = DB::connection('pgsql');
        $this->journalRepository = new JournalRepository($connection);
        $this->expenseService = $this->buildExpenseService($connection);
        $this->incomeService = $this->buildIncomeService($connection);
        $this->correctionExecutor = $this->buildCorrectionExecutor($connection);

        $aggregator = new AccountBalanceAggregator($connection);
        $this->trialBalanceQuery = new TrialBalanceQuery($aggregator);
        $this->profitAndLossQuery = new ProfitAndLossQuery($aggregator);
        $this->balanceSheetQuery = new BalanceSheetQuery($aggregator, $this->profitAndLossQuery);
        $this->generalLedgerQuery = new GeneralLedgerQuery($connection, $aggregator);
        $this->evidenceIndexQuery = new EvidenceIndexQuery($connection);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-cash', 'Asset');
        $this->insertAccount($this->tenantA, 'account-office-supplies', 'Expense');
        $this->insertAccount($this->tenantA, 'account-sales-revenue', 'Revenue');
        $this->insertAccount($this->tenantA, 'account-owner-capital', 'Equity');
        $this->insertAccount($this->tenantA, 'account-loan', 'Liability');

        $this->insertAccount($this->tenantB, 'account-cash-b', 'Asset');
        $this->insertAccount($this->tenantB, 'account-sales-revenue-b', 'Revenue');
    }

    /**
     * Leaves no residual row behind for the next test class sharing this
     * long-lived PostgreSQL instance — in particular, a
     * `posting_idempotency_keys` row created by this class's own last
     * test would otherwise still reference a `journals` row after this
     * class finishes, and a sibling migration test elsewhere that does
     * `delete from journals` without first clearing that table would
     * fail on the foreign key, exactly the cross-test-class
     * contamination class of bug already hardened against once before
     * (M8A, `FinancialDateMigrationTest`).
     */
    protected function tearDown(): void
    {
        if (self::$skipReason === null) {
            DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->delete();
            DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->delete();
            DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->delete();
            DB::connection('pgsql')->table(self::EXPENSE_TABLE)->delete();
            DB::connection('pgsql')->table(self::INCOME_TABLE)->delete();
            DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
            DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
            foreach (['reconciliation_reopenings', 'matches', 'bank_transactions', 'reconciliations', 'bank_statement_import_batches', 'bank_accounts'] as $bankingTable) {
                if (Schema::connection('pgsql')->hasTable($bankingTable)) {
                    DB::connection('pgsql')->table($bankingTable)->delete();
                }
            }
            DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();
        }

        parent::tearDown();
    }

    // --- Golden dataset reconciliation ----------------------------------

    /**
     * The golden dataset: one real Expense (Debit Office Supplies,
     * Credit Cash, RM50.00) and one real Income (Debit Cash, Credit
     * Sales Revenue, RM200.00) posted through their own M7/M9 services
     * — Cash nets Debit RM150.00, and every report must reconcile
     * against these same real Journals, never a separately-maintained
     * total.
     */
    public function test_trial_balance_reconciles_against_real_expense_and_income_postings(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();

        $trialBalance = $this->trialBalanceQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-08-31'));

        $this->assertTrue($trialBalance->isBalanced());
        // Trial Balance totals are the raw sum of each line's own total
        // Debit/Credit column (Office Supplies debit 50.00 + Cash debit
        // 200.00 = 250.00), never a netted figure — this is exactly why
        // the two columns are expected to be equal, not zero.
        $this->assertSame('250.00', $trialBalance->totalDebit()->toDecimalString());
        $this->assertSame('250.00', $trialBalance->totalCredit()->toDecimalString());

        $cashLine = $this->findLine($trialBalance->lines(), 'account-cash');
        $this->assertSame('150.00', $cashLine->netBalance()->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $cashLine->netBalance()->direction());
    }

    public function test_profit_and_loss_reconciles_against_real_postings(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();

        $statement = $this->profitAndLossQuery->forPeriod(
            $this->tenantA,
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );

        $this->assertSame('200.00', $statement->totalRevenue()->toDecimalString());
        $this->assertSame('50.00', $statement->totalExpense()->toDecimalString());
        $this->assertSame('150.00', $statement->netIncome()->toDecimalString());
        $this->assertTrue($statement->isProfit());
    }

    public function test_balance_sheet_reconciles_and_balances_with_unclosed_books_cumulative_net_income(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();

        $balanceSheet = $this->balanceSheetQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-08-31'));

        $this->assertSame('150.00', $balanceSheet->totalAssets()->toDecimalString());
        $this->assertSame('150.00', $balanceSheet->totalLiabilitiesAndEquity()->toDecimalString());
        $this->assertTrue($balanceSheet->isBalanced());
        $this->assertSame('150.00', $balanceSheet->cumulativeNetIncome()->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Credit, $balanceSheet->cumulativeNetIncome()->direction());
    }

    // --- Draft exclusion --------------------------------------------------

    /**
     * A Draft Journal — never Posted — must not appear in, or affect,
     * any report (AETS-009 §5 rule 1).
     */
    public function test_a_draft_journal_never_appears_in_any_report(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();

        $draft = Journal::create($this->tenantA, JournalId::of('journal-draft-0001'), [
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('999.00', $this->myr), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-sales-revenue'), Money::fromDecimalString('999.00', $this->myr), JournalDirection::Credit),
        ], new \DateTimeImmutable('2026-08-20'));
        $this->journalRepository->save($draft);

        $trialBalance = $this->trialBalanceQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-08-31'));
        $cashLine = $this->findLine($trialBalance->lines(), 'account-cash');

        $this->assertSame('150.00', $cashLine->netBalance()->amount()->toDecimalString());
        $this->assertTrue($trialBalance->isBalanced());
    }

    // --- Tenant isolation ---------------------------------------------------

    public function test_reports_never_leak_across_tenants(): void
    {
        $this->postGoldenExpense();
        $this->postGoldenIncome();

        $this->incomeService->record(new RecordIncomeCommand(
            IncomeId::of('income-b-0001'),
            JournalId::of('journal-b-0001'),
            IdempotencyKey::of('key-b-0001'),
            $this->tenantB,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('999.00', $this->myr),
            new \DateTimeImmutable('2026-08-15'),
            AccountId::of('account-sales-revenue-b'),
            AccountId::of('account-cash-b'),
            'Tenant B income',
            null,
        ));

        $trialBalanceA = $this->trialBalanceQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-08-31'));
        $this->assertSame('250.00', $trialBalanceA->totalDebit()->toDecimalString());

        $trialBalanceB = $this->trialBalanceQuery->asOf($this->tenantB, new \DateTimeImmutable('2026-08-31'));
        $this->assertSame('999.00', $trialBalanceB->totalDebit()->toDecimalString());
    }

    // --- financial_date is the report clock, never posted_at ---------------

    /**
     * (AETS-009 §5 rule 5) A Journal's `financial_date` — never its
     * `posted_at` wall-clock timestamp — governs whether it falls
     * inside a report's date range, proven here with a Journal whose
     * real `posted_at` (now, at test-run time) is materially later than
     * the historical `financial_date` used for inclusion.
     */
    public function test_report_inclusion_is_governed_by_financial_date_not_posted_at(): void
    {
        $result = $this->expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-historical-0001'),
            JournalId::of('journal-historical-0001'),
            IdempotencyKey::of('key-historical-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('75.00', $this->myr),
            new \DateTimeImmutable('2026-01-15'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-cash'),
            'Backdated office supplies',
            null,
        ));

        $journal = $this->journalRepository->findById($this->tenantA, $result->expense()->journalId());
        $this->assertNotNull($journal);
        $this->assertSame('2026-01-15', $journal->financialDate()->format('Y-m-d'));
        $this->assertGreaterThan($journal->financialDate(), $journal->postedAt());

        $trialBalance = $this->trialBalanceQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-01-31'));
        $cashLine = $this->findLine($trialBalance->lines(), 'account-cash');
        $this->assertSame('75.00', $cashLine->netBalance()->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Credit, $cashLine->netBalance()->direction());
    }

    // --- Reversal/Replacement correctness -----------------------------------

    /**
     * An M5 Reversal of a real Expense's Journal must net to zero
     * effect across every report — no special-casing in the Reporting
     * layer for corrected Journals (AETS-009 §5 rule 3).
     */
    public function test_a_reversed_expense_nets_to_zero_effect_in_every_report(): void
    {
        $result = $this->postGoldenExpense();

        $this->correctionExecutor->executeReversal(new ReverseJournalCommand(
            IdempotencyKey::of('key-reversal-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-reversal-0001'),
            JournalId::of('journal-reversal-0001'),
            $result->expense()->journalId(),
            new \DateTimeImmutable('2026-08-10'),
        ));

        $trialBalance = $this->trialBalanceQuery->asOf($this->tenantA, new \DateTimeImmutable('2026-08-31'));

        // Each individual Account's own NET balance cancels to zero —
        // the raw Debit/Credit column totals do not (both the original
        // Expense and its Reversal contribute real activity to those
        // columns), which is exactly why the net-per-account balance,
        // not the raw column sum, is the correct proof of "zero effect."
        $this->assertTrue($trialBalance->isBalanced());
        $this->assertTrue($this->findLine($trialBalance->lines(), 'account-cash')->netBalance()->isZero());
        $this->assertTrue($this->findLine($trialBalance->lines(), 'account-office-supplies')->netBalance()->isZero());

        $statement = $this->profitAndLossQuery->forPeriod(
            $this->tenantA,
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );
        $this->assertSame('0.00', $statement->netIncome()->toDecimalString());
    }

    // --- General Ledger traceability -----------------------------------------

    public function test_general_ledger_drill_down_traces_every_entry_back_to_its_real_journal(): void
    {
        $expenseResult = $this->postGoldenExpense();
        $incomeResult = $this->postGoldenIncome(evidenceReference: EvidenceReference::of('invoice-0001'));

        $activity = $this->generalLedgerQuery->forAccountAndPeriod(
            $this->tenantA,
            AccountId::of('account-cash'),
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );

        $this->assertTrue($activity->openingBalance()->isZero());
        $this->assertCount(2, $activity->entries());

        $expenseEntry = $activity->entries()[0];
        $this->assertTrue($expenseEntry->journalId()->equals($expenseResult->expense()->journalId()));
        $this->assertSame(JournalDirection::Credit, $expenseEntry->direction());
        $this->assertSame('50.00', $expenseEntry->amount()->toDecimalString());
        $this->assertSame([], $expenseEntry->evidenceReferences());

        $incomeEntry = $activity->entries()[1];
        $this->assertTrue($incomeEntry->journalId()->equals($incomeResult->income()->journalId()));
        $this->assertSame(JournalDirection::Debit, $incomeEntry->direction());
        $this->assertSame('200.00', $incomeEntry->amount()->toDecimalString());
        $this->assertSame(['invoice-0001'], $incomeEntry->evidenceReferences());

        $this->assertSame('150.00', $activity->closingBalance()->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $activity->closingBalance()->direction());
    }

    // --- Evidence Index accuracy ---------------------------------------------

    public function test_evidence_index_reports_presence_and_explicit_absence_of_evidence(): void
    {
        $expenseResult = $this->postGoldenExpense();
        $incomeResult = $this->postGoldenIncome(evidenceReference: EvidenceReference::of('invoice-0001'));

        $index = $this->evidenceIndexQuery->forPeriod(
            $this->tenantA,
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
        );

        $this->assertCount(2, $index->entries());

        $expenseEntry = $this->findEvidenceEntry($index->entries(), $expenseResult->expense()->journalId()->toString());
        $this->assertFalse($expenseEntry->hasEvidence());
        $this->assertSame([], $expenseEntry->evidenceReferences());

        $incomeEntry = $this->findEvidenceEntry($index->entries(), $incomeResult->income()->journalId()->toString());
        $this->assertTrue($incomeEntry->hasEvidence());
        $this->assertSame(['invoice-0001'], $incomeEntry->evidenceReferences());
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

    private function postGoldenIncome(?EvidenceReference $evidenceReference = null): mixed
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
            $evidenceReference,
        ));
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

    /**
     * @param  list<EvidenceIndexEntry>  $entries
     */
    private function findEvidenceEntry(array $entries, string $journalId): EvidenceIndexEntry
    {
        foreach ($entries as $entry) {
            if ($entry->journalId()->toString() === $journalId) {
                return $entry;
            }
        }

        $this->fail(sprintf('No Evidence Index entry found for Journal "%s".', $journalId));
    }

    private function insertAccount(TenantId $tenantId, string $accountId, string $accountType): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
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

    private function buildCorrectionExecutor(ConnectionInterface $connection): JournalCorrectionTransactionalExecutor
    {
        $journalRepository = new JournalRepository($connection);
        $idempotencyRepository = new PostingIdempotencyRepository($connection);
        $assembler = new JournalCorrectionCandidateAssembler(
            $journalRepository,
            new PostingCommandAccountValidator(new AccountRepository($connection)),
        );

        $idempotencyResolver = new JournalCorrectionIdempotencyResolver(
            $idempotencyRepository,
            $journalRepository,
            new JournalCorrectionLogicalEquivalence,
        );

        return new JournalCorrectionTransactionalExecutor(
            $connection,
            $idempotencyResolver,
            $assembler,
            $journalRepository,
            $idempotencyRepository,
            new AuditEventRepository($connection),
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

        self::forceCleanMigration(self::IDEMPOTENCY_MIGRATION_PATH, [self::IDEMPOTENCY_TABLE]);

        if (! Schema::connection('pgsql')->hasTable(self::AUDIT_EVENT_TABLE)) {
            self::forceCleanMigration(self::AUDIT_EVENT_MIGRATION_PATH, [self::AUDIT_EVENT_TABLE]);
        }

        if (! Schema::connection('pgsql')->hasTable(self::EVIDENCE_LINK_TABLE)) {
            self::forceCleanMigration(self::EVIDENCE_LINK_MIGRATION_PATH, [self::EVIDENCE_LINK_TABLE]);
        }

        self::forceCleanMigration(self::EXPENSE_MIGRATION_PATH, [self::EXPENSE_TABLE]);
        self::forceCleanMigration(self::INCOME_MIGRATION_PATH, [self::INCOME_TABLE]);

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
