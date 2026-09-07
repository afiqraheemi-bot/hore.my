<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Transactions\Income;

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
use App\Domain\Accounting\Posting\PostingCommandPeriodLockValidator;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Accounting\Posting\ReverseJournalCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\Exception\InvalidDepositAccountTypeException;
use App\Domain\Transactions\Income\Exception\InvalidIncomeAccountTypeException;
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
use App\Infrastructure\Transactions\Income\IncomeRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for {@see IncomeRecordingService} (M9) — the
 * first real, non-test consumer of Accounting Core — exercised against
 * a real PostgreSQL instance, every collaborator real, never stubbed.
 *
 * Directly evidences the M9 mandate's acceptance criteria: exactly one
 * Posted Journal per valid Income, correct Debit/Credit mapping, exact
 * amount and business-context preservation, Income<->Journal
 * traceability, optional Evidence linkage, idempotent replay,
 * conflicting-reuse rejection, tenant isolation, Account Type/status
 * rejection, atomicity (fault-injection), and M5 correction integration.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class IncomeRecordingServiceIntegrationTest extends TestCase
{
    private const INCOME_TABLE = 'incomes';

    private const IDEMPOTENCY_TABLE = 'posting_idempotency_keys';

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const EVIDENCE_LINK_TABLE = 'journal_evidence_links';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

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

    private IncomeRecordingService $service;

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

        DB::connection('pgsql')->table(self::INCOME_TABLE)->delete();
        DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->delete();
        DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->delete();
        DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->delete();
        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        if (Schema::connection('pgsql')->hasTable('payments')) {
            DB::connection('pgsql')->table('payment_allocations')->delete();
            DB::connection('pgsql')->table('payments')->delete();
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

        $connection = DB::connection('pgsql');
        $this->journalRepository = new JournalRepository($connection);
        $this->service = $this->buildService($connection);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-sales-revenue', 'Revenue');
        $this->insertAccount($this->tenantA, 'account-cash', 'Asset');
        $this->insertAccount($this->tenantA, 'account-inactive-cash', 'Asset', active: false);
        $this->insertAccount($this->tenantA, 'account-expense', 'Expense');
        $this->insertAccount($this->tenantA, 'account-non-posting-revenue', 'Revenue', postingEligible: false);
        $this->insertAccount($this->tenantB, 'account-sales-revenue-b', 'Revenue');
        $this->insertAccount($this->tenantB, 'account-cash-b', 'Asset');
    }

    // --- Happy path ----------------------------------------------------

    public function test_valid_manual_income_produces_exactly_one_posted_journal(): void
    {
        $result = $this->service->record($this->makeCommand());

        $this->assertTrue($result->isNewlyRecorded());
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(JournalState::Posted, $this->journalRepository->findById($this->tenantA, $result->income()->journalId())?->state());
    }

    public function test_debit_cash_bank_credit_income_with_exact_amount(): void
    {
        $result = $this->service->record($this->makeCommand(amount: '123.45'));

        $journal = $this->journalRepository->findById($this->tenantA, $result->income()->journalId());
        $this->assertNotNull($journal);
        $lines = $journal->lines();

        $this->assertTrue($lines[0]->accountId()->equals(AccountId::of('account-cash')));
        $this->assertSame(JournalDirection::Debit, $lines[0]->direction());
        $this->assertSame('123.45', $lines[0]->money()->toDecimalString());

        $this->assertTrue($lines[1]->accountId()->equals(AccountId::of('account-sales-revenue')));
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertSame('123.45', $lines[1]->money()->toDecimalString());
    }

    public function test_transaction_date_is_preserved_authoritatively(): void
    {
        $command = $this->makeCommand(transactionDate: new \DateTimeImmutable('2026-08-15'));

        $result = $this->service->record($command);

        $this->assertSame('2026-08-15', $result->income()->transactionDate()->format('Y-m-d'));

        $reloaded = $this->incomeRepository()->findById($this->tenantA, $result->income()->id());
        $this->assertSame('2026-08-15', $reloaded?->transactionDate()->format('Y-m-d'));
    }

    /**
     * (M8, AETS-007 §11.1) The Income's own `transaction_date` flows
     * exactly into the resulting Journal's `financial_date` — the
     * ledger-authoritative date, never `created_at` or "today".
     */
    public function test_income_transaction_date_flows_exactly_into_journal_financial_date(): void
    {
        $command = $this->makeCommand(transactionDate: new \DateTimeImmutable('2026-05-01'));

        $result = $this->service->record($command);

        $journal = $this->journalRepository->findById($this->tenantA, $result->income()->journalId());
        $this->assertNotNull($journal);
        $this->assertSame('2026-05-01', $journal->financialDate()->format('Y-m-d'));
        $this->assertNotNull($journal->postedAt());
    }

    public function test_description_and_business_context_are_preserved_after_posting(): void
    {
        $result = $this->service->record($this->makeCommand(description: 'Consulting income'));

        $reloaded = $this->incomeRepository()->findById($this->tenantA, $result->income()->id());
        $this->assertSame('Consulting income', $reloaded?->description());
        $this->assertTrue($reloaded->incomeAccountId()->equals(AccountId::of('account-sales-revenue')));
        $this->assertTrue($reloaded->depositAccountId()->equals(AccountId::of('account-cash')));
    }

    public function test_income_traces_to_the_resulting_journal(): void
    {
        $result = $this->service->record($this->makeCommand());

        $income = $result->income();
        $journal = $this->journalRepository->findById($this->tenantA, $income->journalId());

        $this->assertNotNull($journal);
        $this->assertTrue($journal->id()->equals($income->journalId()));

        $reloaded = $this->incomeRepository()->findByJournalId($this->tenantA, $journal->id());
        $this->assertTrue($reloaded?->id()->equals($income->id()));
    }

    public function test_evidence_reference_is_linked_atomically(): void
    {
        $command = $this->makeCommand(evidenceReference: EvidenceReference::of('receipt-0001'));

        $result = $this->service->record($command);

        $linked = DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)
            ->where('journal_id', $result->income()->journalId()->toString())
            ->pluck('evidence_reference')
            ->all();

        $this->assertSame(['receipt-0001'], $linked);
        $this->assertSame('receipt-0001', $result->income()->evidenceReference()?->toString());
    }

    public function test_no_evidence_reference_produces_no_linkage_row(): void
    {
        $result = $this->service->record($this->makeCommand());

        $this->assertSame(0, DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->where('journal_id', $result->income()->journalId()->toString())->count());
    }

    public function test_an_audit_event_is_produced_for_the_income_posting(): void
    {
        $result = $this->service->record($this->makeCommand());

        $row = DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)
            ->where('journal_id', $result->income()->journalId()->toString())
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
        $this->assertTrue($first->income()->id()->equals($second->income()->id()));
        $this->assertSame(1, DB::connection('pgsql')->table(self::INCOME_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
    }

    public function test_same_key_with_a_materially_different_amount_is_a_conflicting_reuse(): void
    {
        $key = IdempotencyKey::of('key-conflict');
        $this->service->record($this->makeCommand(idempotencyKey: $key, incomeId: IncomeId::of('income-one'), journalId: JournalId::of('journal-one')));

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);

        $this->service->record($this->makeCommand(idempotencyKey: $key, incomeId: IncomeId::of('income-two'), journalId: JournalId::of('journal-two'), amount: '999.00'));
    }

    // --- Tenant isolation -------------------------------------------------

    public function test_tenant_cannot_reference_another_tenants_account(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(tenantId: $this->tenantA, incomeAccountId: AccountId::of('account-sales-revenue-b')));
    }

    public function test_two_tenants_recording_incomes_do_not_interfere(): void
    {
        $resultA = $this->service->record($this->makeCommand(tenantId: $this->tenantA));
        $resultB = $this->service->record(new RecordIncomeCommand(
            IncomeId::of('income-b-0001'),
            JournalId::of('journal-b-0001'),
            IdempotencyKey::of('key-b-0001'),
            $this->tenantB,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString('50.00', $this->myr),
            new \DateTimeImmutable('2026-09-06'),
            AccountId::of('account-sales-revenue-b'),
            AccountId::of('account-cash-b'),
            'Tenant B income',
            null,
        ));

        $this->assertSame(1, DB::connection('pgsql')->table(self::INCOME_TABLE)->where('tenant_id', $this->tenantA->toString())->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::INCOME_TABLE)->where('tenant_id', $this->tenantB->toString())->count());
        $this->assertFalse($resultA->income()->id()->equals($resultB->income()->id()));
    }

    // --- Account validation ----------------------------------------------

    public function test_inactive_deposit_account_is_rejected(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(depositAccountId: AccountId::of('account-inactive-cash')));
    }

    public function test_non_posting_eligible_income_account_is_rejected(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(incomeAccountId: AccountId::of('account-non-posting-revenue')));
    }

    public function test_income_account_with_the_wrong_type_is_rejected(): void
    {
        $this->expectException(InvalidIncomeAccountTypeException::class);

        $this->service->record($this->makeCommand(incomeAccountId: AccountId::of('account-cash')));
    }

    public function test_deposit_account_with_the_wrong_type_is_rejected(): void
    {
        $this->expectException(InvalidDepositAccountTypeException::class);

        $this->service->record($this->makeCommand(depositAccountId: AccountId::of('account-expense')));
    }

    // --- Atomicity ---------------------------------------------------------

    public function test_a_forced_income_insert_failure_rolls_back_the_entire_transaction(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement('ALTER TABLE incomes ADD CONSTRAINT force_test_income_failure CHECK (1 = 0)');

        try {
            try {
                $this->service->record($this->makeCommand());
                $this->fail('Expected the forced CHECK constraint to reject the Income insert.');
            } catch (QueryException) {
                // Expected: a non-duplicate constraint violation,
                // propagated unmodified.
            }
        } finally {
            $connection->statement('ALTER TABLE incomes DROP CONSTRAINT force_test_income_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::INCOME_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->count());
    }

    public function test_an_invalid_account_type_leaves_no_persistence_effect(): void
    {
        try {
            $this->service->record($this->makeCommand(incomeAccountId: AccountId::of('account-cash')));
            $this->fail('Expected the wrong Account Type to be rejected.');
        } catch (InvalidIncomeAccountTypeException) {
            // Expected.
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::INCOME_TABLE)->count());
    }

    // --- M5 correction integration -----------------------------------------

    public function test_a_recorded_incomes_journal_can_be_reversed_via_m5_without_bypassing_accounting_core(): void
    {
        $result = $this->service->record($this->makeCommand());
        $income = $result->income();

        $correctionExecutor = $this->buildCorrectionExecutor(DB::connection('pgsql'));
        $reversalResult = $correctionExecutor->executeReversal(new ReverseJournalCommand(
            IdempotencyKey::of('key-reversal-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-reversal-0001'),
            $income->journalId(),
            new \DateTimeImmutable('2026-09-06'),
        ));

        $this->assertTrue($reversalResult->isNewlyPosted());
        $this->assertSame(JournalDirection::Credit, $reversalResult->journal()->lines()[0]->direction());

        $originalJournal = $this->journalRepository->findById($this->tenantA, $income->journalId());
        $this->assertSame(JournalState::Posted, $originalJournal?->state());
        $this->assertNull($originalJournal->correctionType());

        // The Income record itself is untouched by the correction — it
        // remains immutable, exactly as designed; correction operates
        // entirely at the Journal level via M5, never on this row.
        $reloadedIncome = $this->incomeRepository()->findById($this->tenantA, $income->id());
        $this->assertTrue($reloadedIncome?->journalId()->equals($income->journalId()));
    }

    public function test_reversing_a_draft_journal_is_still_rejected_through_the_same_m5_rules(): void
    {
        // Sanity check that M9 introduces no special-casing: M5's own
        // domain rules apply to an Income's Journal exactly as they do
        // to any other Journal.
        $connection = DB::connection('pgsql');
        $draft = Journal::create($this->tenantA, JournalId::of('journal-draft-income'), [
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('10.00', $this->myr), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-sales-revenue'), Money::fromDecimalString('10.00', $this->myr), JournalDirection::Credit),
        ], new \DateTimeImmutable('2026-08-15'));
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
            new \DateTimeImmutable('2026-08-15'),
        ));
    }

    // --- Fixtures and helpers ------------------------------------------

    /**
     * @param  list<string>  $lines
     */
    private function makeCommand(
        ?TenantId $tenantId = null,
        ?IncomeId $incomeId = null,
        ?JournalId $journalId = null,
        ?IdempotencyKey $idempotencyKey = null,
        string $amount = '50.00',
        ?\DateTimeImmutable $transactionDate = null,
        ?AccountId $incomeAccountId = null,
        ?AccountId $depositAccountId = null,
        string $description = 'Cash sale',
        ?EvidenceReference $evidenceReference = null,
    ): RecordIncomeCommand {
        return new RecordIncomeCommand(
            $incomeId ?? IncomeId::of('income-0001'),
            $journalId ?? JournalId::of('journal-0001'),
            $idempotencyKey ?? IdempotencyKey::of('key-0001'),
            $tenantId ?? $this->tenantA,
            ActorReference::of('actor-0001'),
            Money::fromDecimalString($amount, $this->myr),
            $transactionDate ?? new \DateTimeImmutable('2026-09-06'),
            $incomeAccountId ?? AccountId::of('account-sales-revenue'),
            $depositAccountId ?? AccountId::of('account-cash'),
            $description,
            $evidenceReference,
        );
    }

    private function incomeRepository(): IncomeRepository
    {
        return new IncomeRepository(DB::connection('pgsql'));
    }

    private function buildService(ConnectionInterface $connection): IncomeRecordingService
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

        return new IncomeRecordingService(
            $connection,
            new IncomeAccountTypeValidator($accountRepository),
            new IncomeToPostingCommandTranslator,
            $postingExecutor,
            new IncomeRepository($connection),
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
