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
use App\Domain\Banking\Exception\MatchConfirmationConflictException;
use App\Domain\Banking\Exception\NoSuchMatchCandidateException;
use App\Domain\Banking\MatchConfidence;
use App\Domain\Banking\MatchingService;
use App\Domain\Banking\MatchSourceType;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\ExpenseAccountTypeValidator;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Domain\Transactions\Expense\ExpenseToPostingCommandTranslator;
use App\Domain\Transactions\Expense\RecordExpenseCommand;
use App\Domain\Transactions\Transfer\RecordTransferCommand;
use App\Domain\Transactions\Transfer\TransferAccountTypeValidator;
use App\Domain\Transactions\Transfer\TransferId;
use App\Domain\Transactions\Transfer\TransferRecordingService;
use App\Domain\Transactions\Transfer\TransferToPostingCommandTranslator;
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

        if (! Schema::connection('pgsql')->hasTable('transfers')) {
            self::forceCleanMigration('database/migrations/2026_09_07_050000_create_transfers_table.php', []);
        }

        if (! Schema::connection('pgsql')->hasTable('incomes')) {
            self::forceCleanMigration('database/migrations/2026_09_06_235000_create_incomes_table.php', []);
        }

        if (! Schema::connection('pgsql')->hasTable('owner_equity_transactions')) {
            self::forceCleanMigration('database/migrations/2026_09_07_060000_create_owner_equity_transactions_table.php', []);
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
        $this->assertSame(MatchConfidence::Exact, $candidates[0]->confidence());
    }

    public function test_confirming_a_suggested_match_persists_it(): void
    {
        $expenseResult = $this->expenseService->record($this->makeExpenseCommand());

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));
        $candidate = $candidates[0];

        $result = $this->matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));

        $this->assertTrue($result->isNewMatch());
        $this->assertTrue($result->match()->journalId()->equals($expenseResult->expense()->journalId()));
        $this->assertSame(MatchConfidence::Exact, $result->match()->confidence());
        $this->assertSame('Exact', DB::connection('pgsql')->table(self::MATCH_TABLE)->value('confidence'));
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

    /**
     * BNK-018 (AETS-008 §12.6): re-confirming the exact same pair is a
     * deterministic replay — the same MatchId, never a second row.
     */
    public function test_reconfirming_the_same_pair_replays_the_existing_match(): void
    {
        $this->expenseService->record($this->makeExpenseCommand());

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidate = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'))[0];
        $first = $this->matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));

        $replay = $this->matchingService->confirm($this->tenant, $candidate->bankTransactionId(), $candidate->journalId(), ActorReference::of('actor-0001'));

        $this->assertTrue($first->isNewMatch());
        $this->assertTrue($replay->isReplay());
        $this->assertTrue($replay->match()->id()->equals($first->match()->id()));
        $this->assertSame(1, DB::connection('pgsql')->table(self::MATCH_TABLE)->count());
    }

    /**
     * BNK-018 (AETS-008 §12.6): confirming a *different*, equally
     * eligible Journal against an already-matched BankTransaction is an
     * explicit conflict, never a silent second Match.
     */
    public function test_confirming_a_different_journal_for_an_already_matched_bank_transaction_conflicts(): void
    {
        $this->insertAccount('account-travel', 'Expense');
        $this->expenseService->record($this->makeExpenseCommand());
        $this->expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-0002'),
            JournalId::of('journal-0002'),
            IdempotencyKey::of('key-expense-0002'),
            $this->tenant,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('123.45', $this->myr),
            new \DateTimeImmutable('2026-08-05'),
            AccountId::of('account-travel'),
            AccountId::of('account-bank'),
            'Travel',
            null,
        ));

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));
        $this->assertCount(2, $candidates);
        [$candidateA, $candidateB] = $candidates;

        $this->matchingService->confirm($this->tenant, $candidateA->bankTransactionId(), $candidateA->journalId(), ActorReference::of('actor-0001'));

        $this->expectException(MatchConfirmationConflictException::class);

        $this->matchingService->confirm($this->tenant, $candidateB->bankTransactionId(), $candidateB->journalId(), ActorReference::of('actor-0001'));
    }

    /**
     * BNK-014 (AETS-008 §12.2): a Transfer between two of the Tenant's
     * own onboarded Bank Accounts genuinely produces two bank-statement
     * rows for one accounting event — the suggester must offer the
     * Transfer Journal to both legs, and confirming both is exactly two
     * Matches, never merged into one and never excluded after the
     * first.
     */
    public function test_a_transfer_journal_accepts_two_matches_one_per_leg(): void
    {
        $this->insertAccount('account-bank-two', 'Asset');
        $bankAccountTwo = BankAccount::register(BankAccountId::of('bank-account-0002'), $this->tenant, AccountId::of('account-bank-two'), 'CIMB', null);
        (new BankAccountRepository(DB::connection('pgsql')))->save($bankAccountTwo);

        $transferService = $this->buildTransferService(DB::connection('pgsql'));
        $transferResult = $transferService->record(new RecordTransferCommand(
            TransferId::of('transfer-0001'),
            JournalId::of('journal-transfer-0001'),
            IdempotencyKey::of('key-transfer-0001'),
            $this->tenant,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('300.00', $this->myr),
            new \DateTimeImmutable('2026-08-05'),
            AccountId::of('account-bank'),
            AccountId::of('account-bank-two'),
            'Move to CIMB',
            null,
        ));
        $transferJournalId = $transferResult->transfer()->journalId();

        // Leg 1: money leaving bank-account-0001 (OUT).
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement-1.csv', "date,description,amount,direction,balance,reference\n2026-08-05,Transfer out,300.00,OUT,,\n", $this->myr);
        // Leg 2: money arriving at bank-account-0002 (IN).
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0002'), 'statement-2.csv', "date,description,amount,direction,balance,reference\n2026-08-05,Transfer in,300.00,IN,,\n", $this->myr);

        $legOneCandidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));
        $this->assertCount(1, $legOneCandidates);
        $this->assertTrue($legOneCandidates[0]->journalId()->equals($transferJournalId));
        $this->assertSame(MatchSourceType::Transfer, $legOneCandidates[0]->sourceType());

        $legOneResult = $this->matchingService->confirm($this->tenant, $legOneCandidates[0]->bankTransactionId(), $transferJournalId, ActorReference::of('actor-0001'));
        $this->assertTrue($legOneResult->isNewMatch());

        // The second leg, on the *other* Bank Account, is still offered
        // — the Transfer's first-leg confirmation must not exclude it.
        $legTwoCandidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0002'));
        $this->assertCount(1, $legTwoCandidates);
        $this->assertTrue($legTwoCandidates[0]->journalId()->equals($transferJournalId));

        $legTwoResult = $this->matchingService->confirm($this->tenant, $legTwoCandidates[0]->bankTransactionId(), $transferJournalId, ActorReference::of('actor-0001'));
        $this->assertTrue($legTwoResult->isNewMatch());

        $this->assertSame(2, DB::connection('pgsql')->table(self::MATCH_TABLE)->where('journal_id', $transferJournalId->toString())->count());

        // A third bank transaction that also happens to match exactly
        // (same amount/date/direction) must not be offered a third
        // Match against an already-two-legged Transfer.
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement-3.csv', "date,description,amount,direction,balance,reference\n2026-08-05,Coincidental amount,300.00,OUT,,\n", $this->myr);
        $thirdCandidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));
        $this->assertSame([], $thirdCandidates);
    }

    /**
     * BNK-014 (AETS-008 §12.2): a non-Transfer Journal (Expense here)
     * still accepts at most one confirmed Match — the two-leg exception
     * is exclusive to Transfers.
     */
    public function test_a_non_transfer_journal_still_accepts_only_one_match(): void
    {
        $this->expenseService->record($this->makeExpenseCommand());

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n"
            ."2026-08-05,Coincidental duplicate amount,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $candidates = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));
        $this->assertCount(2, $candidates);

        $this->matchingService->confirm($this->tenant, $candidates[0]->bankTransactionId(), $candidates[0]->journalId(), ActorReference::of('actor-0001'));

        $remaining = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'));
        $this->assertSame([], $remaining);
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

    /**
     * BNK-014 (AETS-008 §12.2): a genuine two-process race between a
     * Transfer's two legs — concurrent confirmations for two different
     * BankTransactions against the *same* Transfer Journal — must both
     * succeed; the Journal-row lock must sequence, not wrongly reject,
     * a legitimate second leg.
     */
    public function test_concurrent_confirmation_of_both_transfer_legs_both_succeed(): void
    {
        $this->insertAccount('account-bank-two', 'Asset');
        $bankAccountTwo = BankAccount::register(BankAccountId::of('bank-account-0002'), $this->tenant, AccountId::of('account-bank-two'), 'CIMB', null);
        (new BankAccountRepository(DB::connection('pgsql')))->save($bankAccountTwo);

        $transferService = $this->buildTransferService(DB::connection('pgsql'));
        $transferResult = $transferService->record(new RecordTransferCommand(
            TransferId::of('transfer-0002'),
            JournalId::of('journal-transfer-0002'),
            IdempotencyKey::of('key-transfer-0002'),
            $this->tenant,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('300.00', $this->myr),
            new \DateTimeImmutable('2026-08-05'),
            AccountId::of('account-bank'),
            AccountId::of('account-bank-two'),
            'Move to CIMB',
            null,
        ));
        $transferJournalId = $transferResult->transfer()->journalId();

        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement-1.csv', "date,description,amount,direction,balance,reference\n2026-08-05,Transfer out,300.00,OUT,,\n", $this->myr);
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0002'), 'statement-2.csv', "date,description,amount,direction,balance,reference\n2026-08-05,Transfer in,300.00,IN,,\n", $this->myr);

        $legOneBankTransactionId = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'))[0]->bankTransactionId();
        $legTwoBankTransactionId = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0002'))[0]->bankTransactionId();

        [$resultA, $resultB] = $this->raceMatchConfirmWorkers(
            [$legOneBankTransactionId->toString(), $transferJournalId->toString(), 'actor-a'],
            [$legTwoBankTransactionId->toString(), $transferJournalId->toString(), 'actor-b'],
        );

        $this->assertSame('success', $resultA['status'] ?? null, json_encode($resultA));
        $this->assertSame('success', $resultB['status'] ?? null, json_encode($resultB));
        $this->assertTrue($resultA['is_new_match'] ?? false);
        $this->assertTrue($resultB['is_new_match'] ?? false);
        $this->assertNotSame($resultA['match_id'], $resultB['match_id']);
        $this->assertSame(2, DB::connection('pgsql')->table(self::MATCH_TABLE)->where('journal_id', $transferJournalId->toString())->count());
    }

    /**
     * BNK-018 (AETS-008 §12.6): a genuine two-process race for the
     * *same* BankTransaction against two different, equally-eligible
     * Journals must let exactly one confirmation win; the loser gets an
     * explicit conflict, never a silent second Match and never a raw
     * database error.
     */
    public function test_concurrent_confirmation_of_the_same_bank_transaction_against_different_journals_conflicts(): void
    {
        $this->insertAccount('account-travel', 'Expense');
        $this->expenseService->record($this->makeExpenseCommand());
        $this->expenseService->record(new RecordExpenseCommand(
            ExpenseId::of('expense-0002'),
            JournalId::of('journal-0002'),
            IdempotencyKey::of('key-expense-0002'),
            $this->tenant,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('123.45', $this->myr),
            new \DateTimeImmutable('2026-08-05'),
            AccountId::of('account-travel'),
            AccountId::of('account-bank'),
            'Travel',
            null,
        ));

        $csv = "date,description,amount,direction,balance,reference\n"
            ."2026-08-05,Card payment,123.45,OUT,,\n";
        $this->importService->import($this->tenant, BankAccountId::of('bank-account-0001'), 'statement.csv', $csv, $this->myr);

        $bankTransactionId = $this->matchingService->suggestFor($this->tenant, BankAccountId::of('bank-account-0001'))[0]->bankTransactionId();

        [$resultA, $resultB] = $this->raceMatchConfirmWorkers(
            [$bankTransactionId->toString(), 'journal-0001', 'actor-a'],
            [$bankTransactionId->toString(), 'journal-0002', 'actor-b'],
        );
        $outcomes = ['A' => $resultA, 'B' => $resultB];

        $succeeded = array_filter($outcomes, static fn (array $r): bool => ($r['status'] ?? null) === 'success');
        $conflicted = array_filter($outcomes, static fn (array $r): bool => ($r['exception'] ?? null) === MatchConfirmationConflictException::class);

        $this->assertCount(1, $succeeded, sprintf('Exactly one concurrent confirmation must succeed. Got: %s', json_encode($outcomes)));
        $this->assertCount(1, $conflicted, sprintf('The other must be an explicit conflict. Got: %s', json_encode($outcomes)));
        $this->assertSame(1, DB::connection('pgsql')->table(self::MATCH_TABLE)->where('bank_transaction_id', $bankTransactionId->toString())->count());
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $workerAArgs  [bankTransactionId, journalId, actor]
     * @param  array{0: string, 1: string, 2: string}  $workerBArgs
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function raceMatchConfirmWorkers(array $workerAArgs, array $workerBArgs): array
    {
        $temporaryDirectory = sys_get_temp_dir();
        $readyA = tempnam($temporaryDirectory, 'match_ready_a_');
        $readyB = tempnam($temporaryDirectory, 'match_ready_b_');
        $goFile = tempnam($temporaryDirectory, 'match_go_');
        $resultA = tempnam($temporaryDirectory, 'match_result_a_');
        $resultB = tempnam($temporaryDirectory, 'match_result_b_');

        foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $file) {
            unlink($file);
        }

        $workerScript = base_path('tests/bin/concurrent_match_confirm_worker.php');

        $processA = proc_open(
            ['php', $workerScript, $this->tenant->toString(), ...$workerAArgs, $readyA, $goFile, $resultA],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $processB = proc_open(
            ['php', $workerScript, $this->tenant->toString(), ...$workerBArgs, $readyB, $goFile, $resultB],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB,
        );

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        try {
            $deadline = microtime(true) + 5.0;
            while (! (file_exists($readyA) && file_exists($readyB))) {
                if (microtime(true) > $deadline) {
                    $this->fail('Timed out waiting for both Match-confirmation workers to signal ready.');
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
                    $this->fail('Timed out waiting for both Match-confirmation worker results.');
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

    private function buildTransferService(ConnectionInterface $connection): TransferRecordingService
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

        return new TransferRecordingService(
            $connection,
            new TransferAccountTypeValidator($accountRepository),
            new TransferToPostingCommandTranslator,
            $postingExecutor,
            new TransferRepository($connection),
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
            $connection,
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

        if (! Schema::connection('pgsql')->hasColumn(self::MATCH_TABLE, 'confidence')) {
            self::forceCleanMigration('database/migrations/2026_09_16_010000_add_confidence_to_matches_table.php', []);
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
