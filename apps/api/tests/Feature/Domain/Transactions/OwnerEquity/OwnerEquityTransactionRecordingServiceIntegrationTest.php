<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Transactions\OwnerEquity;

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
use App\Domain\Transactions\OwnerEquity\Exception\InvalidCashAccountTypeException;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidEquityAccountTypeException;
use App\Domain\Transactions\OwnerEquity\OwnerEquityAccountTypeValidator;
use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionRecordingService;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionToPostingCommandTranslator;
use App\Domain\Transactions\OwnerEquity\RecordOwnerEquityTransactionCommand;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Period\PeriodClosureRepository;
use App\Infrastructure\Accounting\Posting\JournalEvidenceLinkRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use App\Infrastructure\Transactions\OwnerEquity\OwnerEquityTransactionRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Domain\Transactions\Transfer\TransferRecordingServiceIntegrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for {@see OwnerEquityTransactionRecordingService}
 * (M15) — exercised against a real PostgreSQL instance, every
 * collaborator real, never stubbed. Mirrors
 * {@see TransferRecordingServiceIntegrationTest}'s
 * own structure and rigor exactly.
 *
 * Directly evidences the M15 mandate's acceptance criteria: exactly one
 * Posted Journal per valid Contribution/Drawing, the correct
 * movement-type-dependent Debit/Credit mapping, exact amount and
 * business-context preservation, record<->Journal traceability,
 * optional Evidence linkage, idempotent replay, conflicting-reuse
 * rejection, tenant isolation, Account Type rejection, atomicity
 * (fault-injection), and M5 correction integration.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class OwnerEquityTransactionRecordingServiceIntegrationTest extends TestCase
{
    private const TRANSACTION_TABLE = 'owner_equity_transactions';

    private const IDEMPOTENCY_TABLE = 'posting_idempotency_keys';

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const EVIDENCE_LINK_TABLE = 'journal_evidence_links';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const TRANSACTION_MIGRATION_PATH = 'database/migrations/2026_09_07_060000_create_owner_equity_transactions_table.php';

    private const IDEMPOTENCY_MIGRATION_PATH = 'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php';

    private const AUDIT_EVENT_MIGRATION_PATH = 'database/migrations/2026_09_06_200000_create_audit_events_table.php';

    private const EVIDENCE_LINK_MIGRATION_PATH = 'database/migrations/2026_09_06_210000_create_journal_evidence_links_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private OwnerEquityTransactionRecordingService $service;

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

        DB::connection('pgsql')->table(self::TRANSACTION_TABLE)->delete();
        DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->delete();
        DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->delete();
        DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->delete();
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

        $connection = DB::connection('pgsql');
        $this->journalRepository = new JournalRepository($connection);
        $this->service = $this->buildService($connection);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-owner-capital', 'Equity');
        $this->insertAccount($this->tenantA, 'account-bank', 'Asset');
        $this->insertAccount($this->tenantA, 'account-inactive-bank', 'Asset', active: false);
        $this->insertAccount($this->tenantA, 'account-revenue', 'Revenue');
        $this->insertAccount($this->tenantA, 'account-non-posting-bank', 'Asset', postingEligible: false);
        $this->insertAccount($this->tenantB, 'account-owner-capital-b', 'Equity');
        $this->insertAccount($this->tenantB, 'account-bank-b', 'Asset');
    }

    // --- Happy path ----------------------------------------------------

    public function test_a_valid_contribution_produces_exactly_one_posted_journal(): void
    {
        $result = $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution));

        $this->assertTrue($result->isNewlyRecorded());
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(JournalState::Posted, $this->journalRepository->findById($this->tenantA, $result->transaction()->journalId())?->state());
    }

    public function test_a_contribution_debits_cash_credits_equity_with_exact_amount(): void
    {
        $result = $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution, amount: '1000.00'));

        $journal = $this->journalRepository->findById($this->tenantA, $result->transaction()->journalId());
        $this->assertNotNull($journal);
        $lines = $journal->lines();

        $this->assertTrue($lines[0]->accountId()->equals(AccountId::of('account-bank')));
        $this->assertSame(JournalDirection::Debit, $lines[0]->direction());
        $this->assertSame('1000.00', $lines[0]->money()->toDecimalString());

        $this->assertTrue($lines[1]->accountId()->equals(AccountId::of('account-owner-capital')));
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertSame('1000.00', $lines[1]->money()->toDecimalString());
    }

    public function test_a_drawing_debits_equity_credits_cash_with_exact_amount(): void
    {
        $result = $this->service->record($this->makeCommand(OwnerEquityMovementType::Drawing, amount: '300.00'));

        $journal = $this->journalRepository->findById($this->tenantA, $result->transaction()->journalId());
        $this->assertNotNull($journal);
        $lines = $journal->lines();

        $this->assertTrue($lines[0]->accountId()->equals(AccountId::of('account-owner-capital')));
        $this->assertSame(JournalDirection::Debit, $lines[0]->direction());
        $this->assertSame('300.00', $lines[0]->money()->toDecimalString());

        $this->assertTrue($lines[1]->accountId()->equals(AccountId::of('account-bank')));
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertSame('300.00', $lines[1]->money()->toDecimalString());
    }

    public function test_transaction_date_flows_exactly_into_journal_financial_date(): void
    {
        $command = $this->makeCommand(OwnerEquityMovementType::Contribution, transactionDate: new \DateTimeImmutable('2026-05-01'));

        $result = $this->service->record($command);

        $journal = $this->journalRepository->findById($this->tenantA, $result->transaction()->journalId());
        $this->assertNotNull($journal);
        $this->assertSame('2026-05-01', $journal->financialDate()->format('Y-m-d'));
        $this->assertNotNull($journal->postedAt());
    }

    public function test_description_and_movement_type_are_preserved_after_posting(): void
    {
        $result = $this->service->record($this->makeCommand(OwnerEquityMovementType::Drawing, description: 'Owner personal withdrawal'));

        $reloaded = $this->transactionRepository()->findById($this->tenantA, $result->transaction()->id());
        $this->assertSame('Owner personal withdrawal', $reloaded?->description());
        $this->assertSame(OwnerEquityMovementType::Drawing, $reloaded->movementType());
        $this->assertTrue($reloaded->equityAccountId()->equals(AccountId::of('account-owner-capital')));
        $this->assertTrue($reloaded->cashAccountId()->equals(AccountId::of('account-bank')));
    }

    public function test_transaction_traces_to_the_resulting_journal(): void
    {
        $result = $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution));

        $transaction = $result->transaction();
        $journal = $this->journalRepository->findById($this->tenantA, $transaction->journalId());

        $this->assertNotNull($journal);
        $this->assertTrue($journal->id()->equals($transaction->journalId()));

        $reloaded = $this->transactionRepository()->findByJournalId($this->tenantA, $journal->id());
        $this->assertTrue($reloaded?->id()->equals($transaction->id()));
    }

    public function test_evidence_reference_is_linked_atomically(): void
    {
        $command = $this->makeCommand(OwnerEquityMovementType::Contribution, evidenceReference: EvidenceReference::of('bank-slip-0001'));

        $result = $this->service->record($command);

        $linked = DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)
            ->where('journal_id', $result->transaction()->journalId()->toString())
            ->pluck('evidence_reference')
            ->all();

        $this->assertSame(['bank-slip-0001'], $linked);
    }

    public function test_an_audit_event_is_produced_for_the_posting(): void
    {
        $result = $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution));

        $row = DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)
            ->where('journal_id', $result->transaction()->journalId()->toString())
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('JournalPosted', $row->action);
    }

    // --- Idempotency and conflict ---------------------------------------

    public function test_identical_retry_is_idempotent(): void
    {
        $command = $this->makeCommand(OwnerEquityMovementType::Contribution, idempotencyKey: IdempotencyKey::of('key-retry'));

        $first = $this->service->record($command);
        $second = $this->service->record($command);

        $this->assertTrue($first->isNewlyRecorded());
        $this->assertTrue($second->isReplay());
        $this->assertTrue($first->transaction()->id()->equals($second->transaction()->id()));
        $this->assertSame(1, DB::connection('pgsql')->table(self::TRANSACTION_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
    }

    public function test_same_key_with_a_materially_different_movement_type_is_a_conflicting_reuse(): void
    {
        $key = IdempotencyKey::of('key-conflict');
        $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution, idempotencyKey: $key, transactionId: OwnerEquityTransactionId::of('txn-one'), journalId: JournalId::of('journal-one')));

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);

        $this->service->record($this->makeCommand(OwnerEquityMovementType::Drawing, idempotencyKey: $key, transactionId: OwnerEquityTransactionId::of('txn-two'), journalId: JournalId::of('journal-two')));
    }

    // --- Tenant isolation -------------------------------------------------

    public function test_tenant_cannot_reference_another_tenants_account(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution, tenantId: $this->tenantA, equityAccountId: AccountId::of('account-owner-capital-b')));
    }

    public function test_two_tenants_recording_transactions_do_not_interfere(): void
    {
        $resultA = $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution, tenantId: $this->tenantA));
        $resultB = $this->service->record(new RecordOwnerEquityTransactionCommand(
            OwnerEquityTransactionId::of('txn-b-0001'),
            JournalId::of('journal-b-0001'),
            IdempotencyKey::of('key-b-0001'),
            $this->tenantB,
            ActorReference::of('actor-0001'),
            OwnerEquityMovementType::Contribution,
            Money::fromDecimalString('50.00', $this->myr),
            new \DateTimeImmutable('2026-09-07'),
            AccountId::of('account-owner-capital-b'),
            AccountId::of('account-bank-b'),
            'Tenant B contribution',
            null,
        ));

        $this->assertSame(1, DB::connection('pgsql')->table(self::TRANSACTION_TABLE)->where('tenant_id', $this->tenantA->toString())->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::TRANSACTION_TABLE)->where('tenant_id', $this->tenantB->toString())->count());
        $this->assertFalse($resultA->transaction()->id()->equals($resultB->transaction()->id()));
    }

    // --- Account validation ----------------------------------------------

    public function test_inactive_cash_account_is_rejected(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution, cashAccountId: AccountId::of('account-inactive-bank')));
    }

    public function test_non_posting_eligible_cash_account_is_rejected(): void
    {
        $this->expectException(RejectedAccountReferenceException::class);

        $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution, cashAccountId: AccountId::of('account-non-posting-bank')));
    }

    public function test_equity_account_with_the_wrong_type_is_rejected(): void
    {
        $this->expectException(InvalidEquityAccountTypeException::class);

        $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution, equityAccountId: AccountId::of('account-revenue')));
    }

    public function test_cash_account_with_the_wrong_type_is_rejected(): void
    {
        $this->expectException(InvalidCashAccountTypeException::class);

        $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution, cashAccountId: AccountId::of('account-owner-capital')));
    }

    // --- Atomicity ---------------------------------------------------------

    public function test_a_forced_insert_failure_rolls_back_the_entire_transaction(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement('ALTER TABLE owner_equity_transactions ADD CONSTRAINT force_test_owner_equity_failure CHECK (1 = 0)');

        try {
            try {
                $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution));
                $this->fail('Expected the forced CHECK constraint to reject the insert.');
            } catch (QueryException) {
                // Expected: a non-duplicate constraint violation,
                // propagated unmodified.
            }
        } finally {
            $connection->statement('ALTER TABLE owner_equity_transactions DROP CONSTRAINT force_test_owner_equity_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::TRANSACTION_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->count());
    }

    // --- M5 correction integration -----------------------------------------

    public function test_a_recorded_transactions_journal_can_be_reversed_via_m5_without_bypassing_accounting_core(): void
    {
        $result = $this->service->record($this->makeCommand(OwnerEquityMovementType::Contribution));
        $transaction = $result->transaction();

        $correctionExecutor = $this->buildCorrectionExecutor(DB::connection('pgsql'));
        $reversalResult = $correctionExecutor->executeReversal(new ReverseJournalCommand(
            IdempotencyKey::of('key-reversal-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-reversal-0001'),
            $transaction->journalId(),
            new \DateTimeImmutable('2026-09-07'),
        ));

        $this->assertTrue($reversalResult->isNewlyPosted());

        $originalJournal = $this->journalRepository->findById($this->tenantA, $transaction->journalId());
        $this->assertSame(JournalState::Posted, $originalJournal?->state());
        $this->assertNull($originalJournal->correctionType());
    }

    public function test_reversing_a_draft_journal_is_still_rejected_through_the_same_m5_rules(): void
    {
        $connection = DB::connection('pgsql');
        $draft = Journal::create($this->tenantA, JournalId::of('journal-draft-owner-equity'), [
            JournalLine::create(AccountId::of('account-bank'), Money::fromDecimalString('10.00', $this->myr), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-owner-capital'), Money::fromDecimalString('10.00', $this->myr), JournalDirection::Credit),
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

    private function makeCommand(
        OwnerEquityMovementType $movementType,
        ?TenantId $tenantId = null,
        ?OwnerEquityTransactionId $transactionId = null,
        ?JournalId $journalId = null,
        ?IdempotencyKey $idempotencyKey = null,
        string $amount = '500.00',
        ?\DateTimeImmutable $transactionDate = null,
        ?AccountId $equityAccountId = null,
        ?AccountId $cashAccountId = null,
        string $description = 'Owner equity movement',
        ?EvidenceReference $evidenceReference = null,
    ): RecordOwnerEquityTransactionCommand {
        return new RecordOwnerEquityTransactionCommand(
            $transactionId ?? OwnerEquityTransactionId::of('owner-equity-0001'),
            $journalId ?? JournalId::of('journal-0001'),
            $idempotencyKey ?? IdempotencyKey::of('key-0001'),
            $tenantId ?? $this->tenantA,
            ActorReference::of('actor-0001'),
            $movementType,
            Money::fromDecimalString($amount, $this->myr),
            $transactionDate ?? new \DateTimeImmutable('2026-09-07'),
            $equityAccountId ?? AccountId::of('account-owner-capital'),
            $cashAccountId ?? AccountId::of('account-bank'),
            $description,
            $evidenceReference,
        );
    }

    private function transactionRepository(): OwnerEquityTransactionRepository
    {
        return new OwnerEquityTransactionRepository(DB::connection('pgsql'));
    }

    private function buildService(ConnectionInterface $connection): OwnerEquityTransactionRecordingService
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

        return new OwnerEquityTransactionRecordingService(
            $connection,
            new OwnerEquityAccountTypeValidator($accountRepository),
            new OwnerEquityTransactionToPostingCommandTranslator,
            $postingExecutor,
            new OwnerEquityTransactionRepository($connection),
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

        self::forceCleanMigration(self::TRANSACTION_MIGRATION_PATH, [self::TRANSACTION_TABLE]);

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
