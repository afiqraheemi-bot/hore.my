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
use App\Domain\Banking\Exception\BankTransactionAlreadyMatchedException;
use App\Domain\Banking\Exception\NoSuchMatchCandidateException;
use App\Domain\Banking\MatchingService;
use App\Domain\Banking\MatchSourceType;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\ExpenseAccountTypeValidator;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Domain\Transactions\Expense\ExpenseToPostingCommandTranslator;
use App\Domain\Transactions\Expense\RecordExpenseCommand;
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
use App\Infrastructure\Transactions\Expense\ExpenseRepository;
use App\Infrastructure\Transactions\Income\IncomeRepository;
use App\Infrastructure\Transactions\OwnerEquity\OwnerEquityTransactionRepository;
use App\Infrastructure\Transactions\Transfer\TransferRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\Concerns\DropsTablesDependentOnJournalsAndAccounts;
use Tests\TestCase;

/**
 * Integration-level proof for {@see MatchingService} (M18, SRS
 * BNK-005) — exercised against a real PostgreSQL instance, every
 * collaborator real, including a genuine Expense posted through the
 * full M4/M7 pipeline to match against.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class MatchingServiceIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;
    use DropsTablesDependentOnJournalsAndAccounts;

    private const ACCOUNT_TABLE = 'accounts';

    private const BANK_ACCOUNT_TABLE = 'bank_accounts';

    private const MATCH_TABLE = 'matches';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private MatchingService $matchingService;

    private ExpenseRecordingService $expenseService;

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
        // (e.g. one proving `accounts`/`bank_accounts` migration
        // reversibility) may drop `matches` between this class's own
        // one-time ensureMigrated() and any individual test method here
        // actually running — PHPUnit's test *class* execution order is
        // not alphabetical or otherwise guaranteed.
        if (! Schema::connection('pgsql')->hasTable(self::MATCH_TABLE)) {
            self::forceCleanMigration('database/migrations/2026_09_07_120000_create_matches_table.php', []);
        }

        self::cleanSharedAccountingTables();

        $connection = DB::connection('pgsql');
        $this->tenant = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');

        $this->insertAccount('account-bank', 'Asset');
        $this->insertAccount('account-office-supplies', 'Expense');

        $this->expenseService = $this->buildExpenseService($connection);
        $this->importService = $this->buildImportService($connection);
        $this->matchingService = $this->buildMatchingService($connection);

        $bankAccount = BankAccount::register(
            BankAccountId::of('bank-account-0001'),
            $this->tenant,
            AccountId::of('account-bank'),
            'Maybank',
            null,
        );
        (new BankAccountRepository($connection))->save($bankAccount);
    }

    public function test_a_bank_transaction_is_suggested_against_a_matching_expense(): void
    {
        $expenseResult = $this->expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-0001'),
            JournalId::of('journal-0001'),
            IdempotencyKey::of('key-expense-0001'),
            $this->tenant,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('123.45', $this->myr),
            new \DateTimeImmutable('2026-08-05'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-bank'),
            'Office supplies',
            null,
        ));

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment - office supplies,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));

        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates[0]->journalId()->equals($expenseResult->expense()->journalId()));
        $this->assertSame(MatchSourceType::Expense, $candidates[0]->sourceType());
        $this->assertStringContainsString('Office supplies', $candidates[0]->rationale());
    }

    public function test_confirming_a_suggested_match_persists_it(): void
    {
        $expenseResult = $this->expenseService->record($this->makeExpenseCommand());

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));
        $candidate = $candidates[0];

        $match = $this->matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));

        $this->assertTrue($match->journalId()->equals($expenseResult->expense()->journalId()));
        $this->assertSame(1, DB::connection('pgsql')->table(self::MATCH_TABLE)->count());
    }

    public function test_a_confirmed_match_is_never_suggested_again(): void
    {
        $this->expenseService->record($this->makeExpenseCommand());

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidate = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'))[0];
        $this->matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));

        $remaining = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));

        $this->assertSame([], $remaining);
    }

    public function test_confirming_an_already_matched_bank_transaction_is_rejected(): void
    {
        $this->expenseService->record($this->makeExpenseCommand());

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidate = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'))[0];
        $this->matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));

        $this->expectException(BankTransactionAlreadyMatchedException::class);

        $this->matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));
    }

    public function test_confirming_a_journal_that_is_not_a_valid_candidate_is_rejected(): void
    {
        $this->expenseService->record($this->makeExpenseCommand());

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $bankTransaction = (new BankTransactionRepository(DB::connection('pgsql')))->findByBankAccount($this->tenant, BankAccountId::of('bank-account-0001'))[0];

        $this->expectException(NoSuchMatchCandidateException::class);

        $this->matchingService->confirm($this->tenant, $bankTransaction->id(), JournalId::of('journal-does-not-exist'), ActorReference::of('actor-0001'));
    }

    public function test_amount_mismatch_produces_no_candidate(): void
    {
        $this->expenseService->record($this->makeExpenseCommand());

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,999.00,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));

        $this->assertSame([], $candidates);
    }

    private function makeExpenseCommand(): RecordExpenseCommand
    {
        return new RecordExpenseCommand(
            ExpenseId::of('expense-0001'),
            JournalId::of('journal-0001'),
            IdempotencyKey::of('key-expense-0001'),
            $this->tenant,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('123.45', $this->myr),
            new \DateTimeImmutable('2026-08-05'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-bank'),
            'Office supplies',
            null,
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

    private function buildExpenseService(ConnectionInterface $connection): ExpenseRecordingService
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

        $postingExecutor = new PostingCommandTransactionalExecutor(
            $connection,
            $idempotencyResolver,
            $journalExecutor,
            $idempotencyRepository,
            new AuditEventRepository($connection),
            new JournalEvidenceLinkRepository($connection),
        );

        return new ExpenseRecordingService(
            $connection,
            new ExpenseAccountTypeValidator($accountRepository),
            new ExpenseToPostingCommandTranslator,
            $postingExecutor,
            new ExpenseRepository($connection),
        );
    }

    private function buildImportService(ConnectionInterface $connection): BankStatementImportService
    {
        return new BankStatementImportService(
            $connection,
            new CsvBankStatementParser,
            new ImportBatchRepository($connection),
            new BankTransactionRepository($connection),
        );
    }

    private function buildMatchingService(ConnectionInterface $connection): MatchingService
    {
        $suggester = new BankTransactionMatchSuggester(
            $connection,
            new ExpenseRepository($connection),
            new IncomeRepository($connection),
            new TransferRepository($connection),
            new OwnerEquityTransactionRepository($connection),
        );

        return new MatchingService(
            new BankAccountRepository($connection),
            new BankTransactionRepository($connection),
            $suggester,
            new MatchRepository($connection),
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

        self::dropTablesDependentOnJournalsAndAccounts();

        $requiredTables = [
            self::ACCOUNT_TABLE => 'database/migrations/2026_09_04_030000_create_accounts_table.php',
            'journals' => 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php',
            'posting_idempotency_keys' => 'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php',
            'audit_events' => 'database/migrations/2026_09_06_200000_create_audit_events_table.php',
            'journal_evidence_links' => 'database/migrations/2026_09_06_210000_create_journal_evidence_links_table.php',
            'expenses' => 'database/migrations/2026_09_06_220000_create_expenses_table.php',
            self::BANK_ACCOUNT_TABLE => 'database/migrations/2026_09_07_090000_create_bank_accounts_table.php',
            'bank_statement_import_batches' => 'database/migrations/2026_09_07_100000_create_bank_statement_import_batches_table.php',
            'bank_transactions' => 'database/migrations/2026_09_07_110000_create_bank_transactions_table.php',
            self::MATCH_TABLE => 'database/migrations/2026_09_07_120000_create_matches_table.php',
        ];

        foreach ($requiredTables as $table => $migrationPath) {
            if (! Schema::connection('pgsql')->hasTable($table)) {
                self::forceCleanMigration($migrationPath, []);
            }
        }

        if (! Schema::connection('pgsql')->hasColumn('journals', 'financial_date')) {
            self::forceCleanMigration('database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php', []);
        }

        if (! Schema::connection('pgsql')->hasColumn('journals', 'correction_type')) {
            self::forceCleanMigration('database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php', []);
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
