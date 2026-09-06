<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Accounting\Posting;

use App\Domain\Accounting\ChartOfAccounts\Account;
use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountName;
use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\RejectedJournalLineMismatchException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Integration-level proof for
 * `PostingCommandExistingDraftLineValidator` (M4-T11), exercised
 * against a real PostgreSQL instance and the real production
 * `journals`/`journal_lines`/`accounts` migrations — real PostgreSQL
 * is used here only because the fixture/setup needed to construct a
 * genuinely *persisted* existing-Draft scenario requires
 * `JournalRepository`; the validator's own comparison logic is pure,
 * in-memory, and performs no I/O of its own.
 *
 * This is the Posting-command boundary's own, earlier enforcement of
 * the same settled line-immutability behavior
 * `JournalRepository::save()` already protects at persistence time
 * (M3-T10) — `JournalRepository` itself is not modified by this task
 * and remains untouched.
 *
 * There is currently no dedicated ATS-007 test ID for this exact
 * mismatch rule — `POST-T042` ("existing Draft Journal reference is
 * accepted, subject to the rest of the pipeline") is the closest
 * related umbrella behavior, but does not itself test a mismatch
 * scenario. This is a genuine, reported traceability gap, not
 * resolved by inventing or editing an ATS-007 ID in this task.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingCommandExistingDraftLineValidatorTest extends TestCase
{
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

    private PostingCommandExistingDraftLineValidator $validator;

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

        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $this->journalRepository = new JournalRepository(DB::connection('pgsql'));
        $this->validator = new PostingCommandExistingDraftLineValidator;
        $this->tenantA = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');

        foreach (['account-cash', 'account-income', 'account-other'] as $accountId) {
            $this->saveAccount($this->tenantA, $accountId);
        }
    }

    /**
     * Exact same lines, same content, same order — passes, returning
     * void.
     */
    public function test_exact_matching_lines_pass(): void
    {
        $draft = $this->saveDraftJournal('journal-0001', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $command = $this->makeCommand([
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->validator->validate($command, $draft);

        $this->addToAssertionCount(1);
    }

    /**
     * Fewer lines than the persisted Draft is rejected.
     */
    public function test_fewer_lines_rejects(): void
    {
        $draft = $this->saveDraftJournal('journal-0002', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $command = $this->makeCommand([
            $this->debitLine('account-cash', '100.00'),
        ]);

        $this->expectException(RejectedJournalLineMismatchException::class);

        $this->validator->validate($command, $draft);
    }

    /**
     * An additional line beyond the persisted Draft's own is rejected.
     */
    public function test_additional_line_rejects(): void
    {
        $draft = $this->saveDraftJournal('journal-0003', [
            $this->debitLine('account-cash', '60.00'),
            $this->creditLine('account-income', '60.00'),
        ]);

        $command = $this->makeCommand([
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-other', '40.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->expectException(RejectedJournalLineMismatchException::class);

        $this->validator->validate($command, $draft);
    }

    /**
     * The same lines, reordered, is rejected — order is part of what
     * "exact match" means, not merely count and content.
     */
    public function test_reordered_otherwise_identical_lines_reject(): void
    {
        $draft = $this->saveDraftJournal('journal-0004', [
            $this->debitLine('account-cash', '10.00'),
            $this->debitLine('account-other', '20.00'),
            $this->creditLine('account-income', '30.00'),
        ]);

        $command = $this->makeCommand([
            $this->debitLine('account-other', '20.00'),
            $this->debitLine('account-cash', '10.00'),
            $this->creditLine('account-income', '30.00'),
        ]);

        $this->expectException(RejectedJournalLineMismatchException::class);

        $this->validator->validate($command, $draft);
    }

    /**
     * A changed AccountId for an otherwise-matching line is rejected.
     */
    public function test_account_id_change_rejects(): void
    {
        $draft = $this->saveDraftJournal('journal-0005', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $command = $this->makeCommand([
            $this->debitLine('account-other', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->expectException(RejectedJournalLineMismatchException::class);

        $this->validator->validate($command, $draft);
    }

    /**
     * A changed Direction for an otherwise-matching line is rejected.
     */
    public function test_direction_change_rejects(): void
    {
        $draft = $this->saveDraftJournal('journal-0006', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $command = $this->makeCommand([
            $this->creditLine('account-cash', '100.00'),
            $this->debitLine('account-income', '100.00'),
        ]);

        $this->expectException(RejectedJournalLineMismatchException::class);

        $this->validator->validate($command, $draft);
    }

    /**
     * A changed Money amount for an otherwise-matching line is
     * rejected.
     */
    public function test_money_amount_change_rejects(): void
    {
        $draft = $this->saveDraftJournal('journal-0007', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $command = $this->makeCommand([
            $this->debitLine('account-cash', '150.00'),
            $this->creditLine('account-income', '150.00'),
        ]);

        $this->expectException(RejectedJournalLineMismatchException::class);

        $this->validator->validate($command, $draft);
    }

    /**
     * A changed Currency for an otherwise-matching line is rejected —
     * proven via the same reflection-based `currencyOtherThanMyr()`
     * technique already established elsewhere in this suite, since
     * `Currency`'s registry currently supports only `MYR`.
     */
    public function test_currency_change_rejects(): void
    {
        $draft = $this->saveDraftJournal('journal-0008', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $otherCurrency = $this->currencyOtherThanMyr();
        $command = $this->makeCommand([
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-income'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Credit),
        ]);

        $this->expectException(RejectedJournalLineMismatchException::class);

        $this->validator->validate($command, $draft);
    }

    /**
     * The persisted Draft remains completely unchanged after a
     * rejected validation attempt — this class performs no mutation
     * and no persistence of any kind.
     */
    public function test_persisted_draft_remains_unchanged_after_rejection(): void
    {
        $draft = $this->saveDraftJournal('journal-0009', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $beforeHeader = $this->fetchRawHeader('journal-0009');
        $beforeLines = $this->fetchRawLines('journal-0009');

        try {
            $this->validator->validate($this->makeCommand([
                $this->debitLine('account-cash', '999.00'),
                $this->creditLine('account-income', '999.00'),
            ]), $draft);
            $this->fail('Expected RejectedJournalLineMismatchException.');
        } catch (RejectedJournalLineMismatchException $e) {
            // expected
        }

        $this->assertSame($beforeHeader, $this->fetchRawHeader('journal-0009'));
        $this->assertSame($beforeLines, $this->fetchRawLines('journal-0009'));
    }

    /**
     * This class performs no persistence, no transaction, and no
     * call to `Journal::post()` or `JournalRepository::save()`.
     */
    public function test_performs_no_persistence_transaction_or_posting(): void
    {
        $reflection = new ReflectionClass(PostingCommandExistingDraftLineValidator::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->save(', $source);
        $this->assertStringNotContainsString('->post(', $source);
        $this->assertStringNotContainsString('DB::', $source);
        $this->assertStringNotContainsString('transaction(', $source);
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
    private function makeCommand(array $lines): PostingCommand
    {
        return new PostingCommand(
            IdempotencyKey::of('key-0001'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-command-identity'),
            $lines,
            $this->financialDate(),
        );
    }

    private function financialDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-15');
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    private function currencyOtherThanMyr(): Currency
    {
        $reflection = new ReflectionClass(Currency::class);
        $other = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('identifier')->setValue($other, 'XXX');
        $reflection->getProperty('scale')->setValue($other, 2);

        return $other;
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

    /**
     * @return array<string, mixed>
     */
    private function fetchRawHeader(string $journalId): array
    {
        /** @var object{tenant_id: string, journal_id: string, state: string} $row */
        $row = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', $journalId)->firstOrFail();

        return (array) $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRawLines(string $journalId): array
    {
        return DB::connection('pgsql')->table(self::LINE_TABLE)
            ->where('journal_id', $journalId)
            ->orderBy('line_position')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
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
