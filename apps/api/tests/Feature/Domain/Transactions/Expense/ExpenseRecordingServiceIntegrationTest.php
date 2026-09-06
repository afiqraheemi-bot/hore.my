<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Transactions\Expense;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Exception\InvalidReversalTargetException;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
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
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Accounting\Posting\ReverseJournalCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\Exception\InvalidExpenseAccountTypeException;
use App\Domain\Transactions\Expense\Exception\InvalidPaymentAccountTypeException;
use App\Domain\Transactions\Expense\ExpenseAccountTypeValidator;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Domain\Transactions\Expense\ExpenseToPostingCommandTranslator;
use App\Domain\Transactions\Expense\RecordExpenseCommand;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Posting\JournalEvidenceLinkRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use App\Infrastructure\Transactions\Expense\ExpenseRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for {@see ExpenseRecordingService} (M7) — the
 * first real, non-test consumer of Accounting Core — exercised against
 * a real PostgreSQL instance, every collaborator real, never stubbed.
 *
 * Directly evidences the M7 mandate's acceptance criteria: exactly one
 * Posted Journal per valid Expense, correct Debit/Credit mapping, exact
 * amount and business-context preservation, Expense<->Journal
 * traceability, optional Evidence linkage, idempotent replay,
 * conflicting-reuse rejection, tenant isolation, Account Type/status
 * rejection, atomicity (fault-injection), and M5 correction integration.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class ExpenseRecordingServiceIntegrationTest extends TestCase
{
    private const EXPENSE_TABLE = 'expenses';

    private const IDEMPOTENCY_TABLE = 'posting_idempotency_keys';

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const EVIDENCE_LINK_TABLE = 'journal_evidence_links';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const EXPENSE_MIGRATION_PATH = 'database/migrations/2026_09_06_220000_create_expenses_table.php';

    private const IDEMPOTENCY_MIGRATION_PATH = 'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php';

    private const AUDIT_EVENT_MIGRATION_PATH = 'database/migrations/2026_09_06_200000_create_audit_events_table.php';

    private const EVIDENCE_LINK_MIGRATION_PATH = 'database/migrations/2026_09_06_210000_create_journal_evidence_links_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private ExpenseRecordingService $service;

    private JournalRepository $journalRepository;

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
        DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->delete();
        DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->delete();
        DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->delete();
        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $connection = DB::connection('pgsql');
        $this->journalRepository = new JournalRepository($connection);
        $this->service = $this->buildService($connection);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-office-supplies', 'Expense');
        $this->insertAccount($this->tenantA, 'account-cash', 'Asset');
        $this->insertAccount($this->tenantA, 'account-inactive-cash', 'Asset', active: false);
        $this->insertAccount($this->tenantA, 'account-revenue', 'Revenue');
        $this->insertAccount($this->tenantA, 'account-non-posting-expense', 'Expense', postingEligible: false);
        $this->insertAccount($this->tenantB, 'account-office-supplies-b', 'Expense');
        $this->insertAccount($this->tenantB, 'account-cash-b', 'Asset');
    }

    // --- Happy path ----------------------------------------------------

    public function test_valid_manual_expense_produces_exactly_one_posted_journal(): void
    {
        $result = $this->service->record($this->makeCommand());

        $this->assertTrue($result->isNewlyRecorded());
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(JournalState::Posted, $this->journalRepository->findById($this->tenantA, $result->expense()->journalId())?->state());
    }

    public function test_debit_expense_credit_cash_bank_with_exact_amount(): void
    {
        $result = $this->service->record($this->makeCommand(amount: '123.45'));

        $journal = $this->journalRepository->findById($this->tenantA, $result->expense()->journalId());
        $this->assertNotNull($journal);
        $lines = $journal->lines();

        $this->assertTrue($lines[0]->accountId()->equals(AccountId::of('account-office-supplies')));
        $this->assertSame(JournalDirection::Debit, $lines[0]->direction());
        $this->assertSame('123.45', $lines[0]->money()->toDecimalString());

        $this->assertTrue($lines[1]->accountId()->equals(AccountId::of('account-cash')));
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertSame('123.45', $lines[1]->money()->toDecimalString());
    }

    public function test_transaction_date_is_preserved_authoritatively(): void
    {
        $command = $this->makeCommand(transactionDate: new \DateTimeImmutable('2026-08-15'));

        $result = $this->service->record($command);

        $this->assertSame('2026-08-15', $result->expense()->transactionDate()->format('Y-m-d'));

        $reloaded = $this->expenseRepository()->findById($this->tenantA, $result->expense()->id());
        $this->assertSame('2026-08-15', $reloaded?->transactionDate()->format('Y-m-d'));
    }

    public function test_description_and_business_context_are_preserved_after_posting(): void
    {
        $result = $this->service->record($this->makeCommand(description: 'Parking for client site visit'));

        $reloaded = $this->expenseRepository()->findById($this->tenantA, $result->expense()->id());
        $this->assertSame('Parking for client site visit', $reloaded?->description());
        $this->assertTrue($reloaded->expenseAccountId()->equals(AccountId::of('account-office-supplies')));
        $this->assertTrue($reloaded->paymentAccountId()->equals(AccountId::of('account-cash')));
    }

    public function test_expense_traces_to_the_resulting_journal(): void
    {
        $result = $this->service->record($this->makeCommand());

        $expense = $result->expense();
        $journal = $this->journalRepository->findById($this->tenantA, $expense->journalId());

        $this->assertNotNull($journal);
        $this->assertTrue($journal->id()->equals($expense->journalId()));

        $reloaded = $this->expenseRepository()->findByJournalId($this->tenantA, $journal->id());
        $this->assertTrue($reloaded?->id()->equals($expense->id()));
    }

    public function test_evidence_reference_is_linked_atomically(): void
    {
        $command = $this->makeCommand(evidenceReference: EvidenceReference::of('receipt-0001'));

        $result = $this->service->record($command);

        $linked = DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)
            ->where('journal_id', $result->expense()->journalId()->toString())
            ->pluck('evidence_reference')
            ->all();

        $this->assertSame(['receipt-0001'], $linked);
        $this->assertSame('receipt-0001', $result->expense()->evidenceReference()?->toString());
    }

    public function test_no_evidence_reference_produces_no_linkage_row(): void
    {
        $result = $this->service->record($this->makeCommand());

        $this->assertSame(0, DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->where('journal_id', $result->expense()->journalId()->toString())->count());
    }

    public function test_an_audit_event_is_produced_for_the_expense_posting(): void
    {
        $result = $this->service->record($this->makeCommand());

        $row = DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)
            ->where('journal_id', $result->expense()->journalId()->toString())
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('JournalPosted', $row->action);
    }

    // --- Idempotency and conflict ---------------------------------------

    public function test_identical_retry_is_idempotent(): void
    {
        $command = $this->makeCommand(idempotencyKey: IdempotencyKey::of('key-retry'));

        $first = $this->service->record($command);
        $second = $this->service->record($command);

        $this->assertTrue($first->isNewlyRecorded());
        $this->assertTrue($second->isReplay());
        $this->assertTrue($first->expense()->id()->equals($second->expense()->id()));
        $this->assertSame(1, DB::connection('pgsql')->table(self::EXPENSE_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
    }

    public function test_same_key_with_a_materially_different_amount_is_a_conflicting_reuse(): void
    {
        $key = IdempotencyKey::of('key-conflict');
        $this->service->record($this->makeCommand(idempotencyKey: $key, expenseId: ExpenseId::of('expense-one'), journalId: JournalId::of('journal-one')));

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);

        $this->service->record($this->makeCommand(idempotencyKey: $key, expenseId: ExpenseId::of('expense-two'), journalId: JournalId::of('journal-two'), amount: '999.00'));
    }

    // --- Tenant isolation -------------------------------------------------

    public function test_tenant_cannot_reference_another_tenants_account(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(tenantId: $this->tenantA, expenseAccountId: AccountId::of('account-office-supplies-b')));
    }

    public function test_two_tenants_recording_expenses_do_not_interfere(): void
    {
        $resultA = $this->service->record($this->makeCommand(tenantId: $this->tenantA));
        $resultB = $this->service->record(new RecordExpenseCommand(
            ExpenseId::of('expense-b-0001'),
            JournalId::of('journal-b-0001'),
            IdempotencyKey::of('key-b-0001'),
            $this->tenantB,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('50.00', $this->myr),
            new \DateTimeImmutable('2026-09-06'),
            AccountId::of('account-office-supplies-b'),
            AccountId::of('account-cash-b'),
            'Tenant B expense',
            null,
        ));

        $this->assertSame(1, DB::connection('pgsql')->table(self::EXPENSE_TABLE)->where('tenant_id', $this->tenantA->toString())->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::EXPENSE_TABLE)->where('tenant_id', $this->tenantB->toString())->count());
        $this->assertFalse($resultA->expense()->id()->equals($resultB->expense()->id()));
    }

    // --- Account validation ----------------------------------------------

    public function test_inactive_payment_account_is_rejected(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(paymentAccountId: AccountId::of('account-inactive-cash')));
    }

    public function test_non_posting_eligible_expense_account_is_rejected(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(expenseAccountId: AccountId::of('account-non-posting-expense')));
    }

    public function test_expense_account_with_the_wrong_type_is_rejected(): void
    {
        $this->expectException(InvalidExpenseAccountTypeException::class);

        $this->service->record($this->makeCommand(expenseAccountId: AccountId::of('account-cash')));
    }

    public function test_payment_account_with_the_wrong_type_is_rejected(): void
    {
        $this->expectException(InvalidPaymentAccountTypeException::class);

        $this->service->record($this->makeCommand(paymentAccountId: AccountId::of('account-revenue')));
    }

    // --- Atomicity ---------------------------------------------------------

    public function test_a_forced_expense_insert_failure_rolls_back_the_entire_transaction(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement('ALTER TABLE expenses ADD CONSTRAINT force_test_expense_failure CHECK (1 = 0)');

        try {
            try {
                $this->service->record($this->makeCommand());
                $this->fail('Expected the forced CHECK constraint to reject the Expense insert.');
            } catch (QueryException) {
                // Expected: a non-duplicate constraint violation,
                // propagated unmodified.
            }
        } finally {
            $connection->statement('ALTER TABLE expenses DROP CONSTRAINT force_test_expense_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::EXPENSE_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->count());
    }

    public function test_an_invalid_account_type_leaves_no_persistence_effect(): void
    {
        try {
            $this->service->record($this->makeCommand(expenseAccountId: AccountId::of('account-cash')));
            $this->fail('Expected the wrong Account Type to be rejected.');
        } catch (InvalidExpenseAccountTypeException) {
            // Expected.
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::EXPENSE_TABLE)->count());
    }

    // --- M5 correction integration -----------------------------------------

    public function test_a_recorded_expenses_journal_can_be_reversed_via_m5_without_bypassing_accounting_core(): void
    {
        $result = $this->service->record($this->makeCommand());
        $expense = $result->expense();

        $correctionExecutor = $this->buildCorrectionExecutor(DB::connection('pgsql'));
        $reversalResult = $correctionExecutor->executeReversal(new ReverseJournalCommand(
            IdempotencyKey::of('key-reversal-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-reversal-0001'),
            $expense->journalId(),
        ));

        $this->assertTrue($reversalResult->isNewlyPosted());
        $this->assertSame(JournalDirection::Credit, $reversalResult->journal()->lines()[0]->direction());

        $originalJournal = $this->journalRepository->findById($this->tenantA, $expense->journalId());
        $this->assertSame(JournalState::Posted, $originalJournal?->state());
        $this->assertNull($originalJournal->correctionType());

        // The Expense record itself is untouched by the correction — it
        // remains immutable, exactly as designed; correction operates
        // entirely at the Journal level via M5, never on this row.
        $reloadedExpense = $this->expenseRepository()->findById($this->tenantA, $expense->id());
        $this->assertTrue($reloadedExpense?->journalId()->equals($expense->journalId()));
    }

    public function test_reversing_a_draft_journal_is_still_rejected_through_the_same_m5_rules(): void
    {
        // Sanity check that M7 introduces no special-casing: M5's own
        // domain rules apply to an Expense's Journal exactly as they do
        // to any other Journal.
        $connection = DB::connection('pgsql');
        $draft = Journal::create($this->tenantA, JournalId::of('journal-draft-expense'), [
            JournalLine::create(AccountId::of('account-office-supplies'), Money::fromDecimalString('10.00', $this->myr), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('10.00', $this->myr), JournalDirection::Credit),
        ]);
        $this->journalRepository->save($draft);

        $correctionExecutor = $this->buildCorrectionExecutor($connection);

        $this->expectException(InvalidReversalTargetException::class);

        $correctionExecutor->executeReversal(new ReverseJournalCommand(
            IdempotencyKey::of('key-reversal-draft'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-reversal-draft'),
            $draft->id(),
        ));
    }

    // --- Fixtures and helpers ------------------------------------------

    /**
     * @param  list<string>  $lines
     */
    private function makeCommand(
        ?TenantId $tenantId = null,
        ?ExpenseId $expenseId = null,
        ?JournalId $journalId = null,
        ?IdempotencyKey $idempotencyKey = null,
        string $amount = '50.00',
        ?\DateTimeImmutable $transactionDate = null,
        ?AccountId $expenseAccountId = null,
        ?AccountId $paymentAccountId = null,
        string $description = 'Office supplies',
        ?EvidenceReference $evidenceReference = null,
    ): RecordExpenseCommand {
        return new RecordExpenseCommand(
            $expenseId ?? ExpenseId::of('expense-0001'),
            $journalId ?? JournalId::of('journal-0001'),
            $idempotencyKey ?? IdempotencyKey::of('key-0001'),
            $tenantId ?? $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString($amount, $this->myr),
            $transactionDate ?? new \DateTimeImmutable('2026-09-06'),
            $expenseAccountId ?? AccountId::of('account-office-supplies'),
            $paymentAccountId ?? AccountId::of('account-cash'),
            $description,
            $evidenceReference,
        );
    }

    private function expenseRepository(): ExpenseRepository
    {
        return new ExpenseRepository(DB::connection('pgsql'));
    }

    private function buildService(ConnectionInterface $connection): ExpenseRecordingService
    {
        $journalRepository = new JournalRepository($connection);
        $accountRepository = new AccountRepository($connection);
        $idempotencyRepository = new PostingIdempotencyRepository($connection);

        $journalExecutor = new PostingCommandJournalExecutor(
            new PostingCommandJournalStateResolver($journalRepository),
            new PostingCommandAccountValidator($accountRepository),
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

    private function insertAccount(TenantId $tenantId, string $accountId, string $accountType, bool $active = true, bool $postingEligible = true): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId->toString().$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => $accountType,
            'account_origin' => 'UserCreated',
            'active' => $active,
            'posting_eligible' => $postingEligible,
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

        if (! Schema::connection('pgsql')->hasTable(self::JOURNAL_TABLE)) {
            self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);
            self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);
        }

        self::forceCleanMigration(self::IDEMPOTENCY_MIGRATION_PATH, [self::IDEMPOTENCY_TABLE]);

        if (! Schema::connection('pgsql')->hasTable(self::AUDIT_EVENT_TABLE)) {
            self::forceCleanMigration(self::AUDIT_EVENT_MIGRATION_PATH, [self::AUDIT_EVENT_TABLE]);
        }

        if (! Schema::connection('pgsql')->hasTable(self::EVIDENCE_LINK_TABLE)) {
            self::forceCleanMigration(self::EVIDENCE_LINK_MIGRATION_PATH, [self::EVIDENCE_LINK_TABLE]);
        }

        self::forceCleanMigration(self::EXPENSE_MIGRATION_PATH, [self::EXPENSE_TABLE]);

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
