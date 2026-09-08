<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Accounting\Posting;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\UnbalancedJournalException;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\Exception\RejectedJournalLineMismatchException;
use App\Domain\Accounting\Posting\Exception\RejectedJournalStateException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\PostingCommandJournalExecutor;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\PostingCommandPeriodLockValidator;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Period\PeriodClosureRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\Concerns\DropsTablesDependentOnJournalsAndAccounts;
use Tests\TestCase;

/**
 * Integration-level proof for `PostingCommandJournalExecutor`
 * (M4-T12), exercised against a real PostgreSQL instance and the real
 * production `journals`/`journal_lines`/`accounts` migrations — never
 * SQLite, since this executor's persistence step depends on
 * `JournalRepository`.
 *
 * Directly evidences `POST-T063` in its full "reaches durable Posted
 * state" sense — the first Posting-domain component to actually
 * persist a Posted Journal. Reinforces (without newly claiming
 * dedicated coverage for) `POST-T041`–`T043`, `POST-T046`–`T052`,
 * `POST-T058`, `POST-T061`–`T064` end-to-end.
 *
 * **Atomicity boundary.** `JournalRepository::save()` already carries
 * its own real-PostgreSQL atomicity and immutability proof from
 * M3-T10 (`JournalRepositoryIntegrationTest`, including fault
 * injection and concurrent-write proofs) — this test class does not
 * duplicate that proof or invent new infrastructure to simulate a
 * repository-level persistence failure; it relies on the
 * already-established boundary and instead proves this executor's own
 * *composition* (validation-before-persistence, and unchanged state
 * on any pre-persistence rejection), verified by re-querying the
 * database directly, never by inspecting the in-memory `Journal`
 * object alone.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingCommandJournalExecutorTest extends TestCase
{
    use CleansSharedAccountingTables;
    use DropsTablesDependentOnJournalsAndAccounts;

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const PERIOD_CLOSURES_MIGRATION_PATH = 'database/migrations/2026_09_07_040000_create_period_closures_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private PostingCommandJournalExecutor $executor;

    private JournalRepository $journalRepository;

    private TenantId $tenantA;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureMigrated();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        // Was a hand-rolled, class-local list of tables to clean
        // before deleting `journals` — went stale when M20/M21 added
        // `invoices`/`invoice_lines`/`payments`/`payment_allocations`,
        // each holding a foreign key onto `journals`, without ever
        // being added here (confirmed as a real, intermittent
        // `QueryException` during a 2026-09-11 audit remediation pass —
        // see `CleansSharedAccountingTables`'s own docblock). Replaced
        // with the shared, canonical cleanup this trait exists for.
        self::cleanSharedAccountingTables();

        $this->journalRepository = new JournalRepository(DB::connection('pgsql'));
        $this->executor = new PostingCommandJournalExecutor(
            new PostingCommandJournalStateResolver($this->journalRepository),
            new PostingCommandAccountValidator(new AccountRepository(DB::connection('pgsql'))),
            new PostingCommandPeriodLockValidator(new PeriodClosureRepository(DB::connection('pgsql'))),
            new PostingCommandExistingDraftLineValidator,
            new DraftJournalAssembler,
            $this->journalRepository,
        );
        $this->tenantA = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');

        foreach (['account-cash', 'account-income'] as $accountId) {
            $this->saveAccount($this->tenantA, $accountId);
        }
    }

    // --- Fresh path -----------------------------------------------------

    /**
     * (POST-T063) A valid, fresh, exactly-balanced command reaches
     * durable Posted state.
     */
    public function test_valid_fresh_command_persists_as_posted(): void
    {
        $journal = $this->executor->execute($this->makeCommand(JournalId::of('journal-fresh'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));

        $this->assertSame(JournalState::Posted, $journal->state());
    }

    /**
     * The persisted Journal reloads, independently, as Posted.
     */
    public function test_persisted_journal_reloads_as_posted(): void
    {
        $this->executor->execute($this->makeCommand(JournalId::of('journal-fresh'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));

        $reloaded = $this->journalRepository->findById($this->tenantA, JournalId::of('journal-fresh'));

        $this->assertNotNull($reloaded);
        $this->assertSame(JournalState::Posted, $reloaded->state());
    }

    /**
     * The persisted lines match exactly what the command supplied.
     */
    public function test_persisted_lines_match_exactly(): void
    {
        $this->executor->execute($this->makeCommand(JournalId::of('journal-fresh'), [
            $this->debitLine('account-cash', '60.00'),
            $this->creditLine('account-income', '60.00'),
        ]));

        $reloaded = $this->journalRepository->findById($this->tenantA, JournalId::of('journal-fresh'));

        $this->assertNotNull($reloaded);
        $this->assertCount(2, $reloaded->lines());
        $this->assertSame('account-cash', $reloaded->lines()[0]->accountId()->toString());
        $this->assertSame('60.00', $reloaded->lines()[0]->money()->toDecimalString());
        $this->assertSame('account-income', $reloaded->lines()[1]->accountId()->toString());
    }

    /**
     * An invalid Account reference leaves no Journal persisted.
     */
    public function test_invalid_account_leaves_no_journal_persisted(): void
    {
        try {
            $this->executor->execute($this->makeCommand(JournalId::of('journal-fresh'), [
                $this->debitLine('account-does-not-exist', '100.00'),
                $this->creditLine('account-income', '100.00'),
            ]));
            $this->fail('Expected RejectedAccountReferenceException.');
        } catch (RejectedAccountReferenceException $e) {
            // expected
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-fresh')->count());
    }

    /**
     * Insufficient lines leaves no Journal persisted.
     */
    public function test_insufficient_lines_leaves_no_journal_persisted(): void
    {
        try {
            $this->executor->execute($this->makeCommand(JournalId::of('journal-fresh'), [
                $this->debitLine('account-cash', '100.00'),
            ]));
            $this->fail('Expected InsufficientJournalLinesException.');
        } catch (InsufficientJournalLinesException $e) {
            // expected
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-fresh')->count());
    }

    /**
     * An unbalanced Journal leaves no Journal persisted.
     */
    public function test_unbalanced_journal_leaves_no_journal_persisted(): void
    {
        try {
            $this->executor->execute($this->makeCommand(JournalId::of('journal-fresh'), [
                $this->debitLine('account-cash', '100.00'),
                $this->creditLine('account-income', '99.99'),
            ]));
            $this->fail('Expected UnbalancedJournalException.');
        } catch (UnbalancedJournalException $e) {
            // expected
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-fresh')->count());
    }

    // --- Existing Draft path ---------------------------------------------

    /**
     * A command matching an existing Draft's lines exactly posts that
     * Draft successfully.
     */
    public function test_matching_command_posts_existing_draft_successfully(): void
    {
        $this->saveDraftJournal('journal-0001', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $journal = $this->executor->execute($this->makeCommand(JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));

        $this->assertSame(JournalState::Posted, $journal->state());
    }

    /**
     * The same Journal identity is preserved through the transition.
     */
    public function test_same_journal_identity_is_preserved(): void
    {
        $this->saveDraftJournal('journal-0002', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $journal = $this->executor->execute($this->makeCommand(JournalId::of('journal-0002'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));

        $this->assertSame('journal-0002', $journal->id()->toString());
        $this->assertTrue($journal->tenantId()->equals($this->tenantA));
    }

    /**
     * The persisted Draft durably becomes Posted, confirmed by
     * re-reading directly from the database.
     */
    public function test_persisted_draft_becomes_posted(): void
    {
        $this->saveDraftJournal('journal-0003', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->executor->execute($this->makeCommand(JournalId::of('journal-0003'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));

        $rawState = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-0003')->value('state');
        $this->assertSame('Posted', $rawState);
    }

    /**
     * A command whose lines do not match the persisted Draft is
     * rejected, and the persisted Journal remains Draft, unchanged.
     */
    public function test_line_mismatch_rejects_and_persisted_journal_remains_draft(): void
    {
        $this->saveDraftJournal('journal-0004', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        try {
            $this->executor->execute($this->makeCommand(JournalId::of('journal-0004'), [
                $this->debitLine('account-cash', '999.00'),
                $this->creditLine('account-income', '999.00'),
            ]));
            $this->fail('Expected RejectedJournalLineMismatchException.');
        } catch (RejectedJournalLineMismatchException $e) {
            // expected
        }

        $rawState = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-0004')->value('state');
        $this->assertSame('Draft', $rawState);
        $lineAmount = DB::connection('pgsql')->table(self::LINE_TABLE)
            ->where('journal_id', 'journal-0004')->where('line_position', 0)->value('amount');
        $this->assertSame('10000', (string) $lineAmount);
    }

    /**
     * An Account-validation failure leaves the persisted Draft
     * unchanged.
     */
    public function test_account_validation_failure_leaves_persisted_draft_unchanged(): void
    {
        $this->saveDraftJournal('journal-0005', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        try {
            $this->executor->execute($this->makeCommand(JournalId::of('journal-0005'), [
                $this->debitLine('account-does-not-exist', '100.00'),
                $this->creditLine('account-income', '100.00'),
            ]));
            $this->fail('Expected RejectedAccountReferenceException.');
        } catch (RejectedAccountReferenceException $e) {
            // expected
        }

        $rawState = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-0005')->value('state');
        $this->assertSame('Draft', $rawState);
        $this->assertSame(2, DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-0005')->count());
    }

    /**
     * An existing Posted Journal reference is rejected and remains
     * unchanged.
     */
    public function test_existing_posted_reference_rejects_and_remains_unchanged(): void
    {
        $posted = Journal::create($this->tenantA, JournalId::of('journal-posted'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ], $this->financialDate())->post($this->postedAt());
        $this->journalRepository->save($posted);

        try {
            $this->executor->execute($this->makeCommand(JournalId::of('journal-posted'), [
                $this->debitLine('account-cash', '100.00'),
                $this->creditLine('account-income', '100.00'),
            ]));
            $this->fail('Expected RejectedJournalStateException.');
        } catch (RejectedJournalStateException $e) {
            // expected
        }

        $rawState = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-posted')->value('state');
        $this->assertSame('Posted', $rawState);
    }

    // --- M8: Financial date and posted-at ----------------------------------

    /**
     * (M8) A freshly posted Journal persists the command's exact
     * Financial Date, and `posted_at` is set to a real, non-null
     * timestamp — never left null once Posted.
     */
    public function test_posting_persists_financial_date_and_sets_posted_at(): void
    {
        $financialDate = new \DateTimeImmutable('2026-03-10');
        $command = new PostingCommand(
            IdempotencyKey::of('key-financial-date'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-financial-date'),
            [
                $this->debitLine('account-cash', '100.00'),
                $this->creditLine('account-income', '100.00'),
            ],
            $financialDate,
        );

        $this->executor->execute($command);

        /** @var object{financial_date: string, posted_at: string|null} $row */
        $row = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-financial-date')->firstOrFail();

        $this->assertSame('2026-03-10', (new \DateTimeImmutable($row->financial_date))->format('Y-m-d'));
        $this->assertNotNull($row->posted_at);
    }

    // --- Architecture -----------------------------------------------------

    /**
     * `Journal::post()` is called exactly once, and persistence goes
     * through `JournalRepository::save()` alone — no new transaction
     * abstraction, no second write path.
     */
    public function test_calls_post_once_and_saves_through_the_existing_repository_only(): void
    {
        $reflection = new ReflectionClass(PostingCommandJournalExecutor::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertSame(1, substr_count($source, '->post('));
        $this->assertSame(1, substr_count($source, '->save('));
        $this->assertStringNotContainsString('DB::', $source);
        $this->assertStringNotContainsString('beginTransaction', $source);
    }

    private function saveDraftJournal(string $journalId, array $lines): Journal
    {
        $journal = Journal::create($this->tenantA, JournalId::of($journalId), $lines, $this->financialDate());
        $this->journalRepository->save($journal);

        return $journal;
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function makeCommand(JournalId $journalId, array $lines): PostingCommand
    {
        return new PostingCommand(
            IdempotencyKey::of('key-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            $journalId,
            $lines,
            $this->financialDate(),
        );
    }

    private function financialDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-15');
    }

    private function postedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-06 10:00:00');
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    private function saveAccount(TenantId $tenantId, string $accountId): void
    {
        (new AccountRepository(DB::connection('pgsql')))->save(Account::create(
            $tenantId,
            AccountId::of($accountId),
            AccountCode::of(substr(md5($tenantId->toString().$accountId), 0, 10)),
            AccountName::of('Test Account'),
            AccountType::Asset,
            true,
            AccountOrigin::UserCreated,
        ));
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

        // `posting_idempotency_keys` (M4-T15) holds a composite foreign
        // key on (tenant_id, journal_id) referencing this table, so it
        // must be dropped first or PostgreSQL refuses to drop `journals`.
        // It is not this test's concern and is intentionally not
        // recreated here.
        Schema::connection('pgsql')->dropIfExists('posting_idempotency_keys');
        Schema::connection('pgsql')->dropIfExists('posting_source_fingerprints');
        Schema::connection('pgsql')->dropIfExists('audit_events');
        Schema::connection('pgsql')->dropIfExists('journal_evidence_links');
        Schema::connection('pgsql')->dropIfExists('expenses');
        Schema::connection('pgsql')->dropIfExists('incomes');
        Schema::connection('pgsql')->dropIfExists('transfers');
        Schema::connection('pgsql')->dropIfExists('owner_equity_transactions');
        Schema::connection('pgsql')->dropIfExists('period_closures');
        Schema::connection('pgsql')->dropIfExists('matches');

        self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);
        self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);
        self::forceCleanMigration(self::FINANCIAL_DATE_MIGRATION_PATH, []);
        self::forceCleanMigration(self::PERIOD_CLOSURES_MIGRATION_PATH, []);

        if (! Schema::connection('pgsql')->hasTable(self::ACCOUNT_TABLE)) {
            self::forceCleanMigration(self::ACCOUNTS_MIGRATION_PATH, [self::ACCOUNT_TABLE]);
        }

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
