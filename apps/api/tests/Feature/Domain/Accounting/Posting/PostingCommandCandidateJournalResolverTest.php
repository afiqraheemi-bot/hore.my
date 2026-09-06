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
use App\Domain\Accounting\Journal\Exception\MixedCurrencyJournalException;
use App\Domain\Accounting\Journal\Exception\UnbalancedJournalException;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\DraftJournalAssembler;
use App\Domain\Accounting\Posting\Exception\RejectedJournalStateException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandCandidateJournalResolver;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
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
 * `PostingCommandCandidateJournalResolver` (M4-T10), exercised against
 * a real PostgreSQL instance and the real production
 * `journals`/`journal_lines`/`accounts` migrations — never SQLite,
 * mirroring the precedent established for
 * `PostingCommandJournalStateResolverTest` (M4-T9), since this class
 * composes that same `JournalRepository`-dependent state resolver.
 *
 * Direct coverage for `POST-T044`: a fresh Journal identity
 * accompanied by an incomplete or invalid line payload fails through
 * the same fresh-assembly validation path a valid fresh command uses
 * — `DraftJournalAssembler`'s own already-established
 * `Journal::create()` delegation, not a new check this class invents.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingCommandCandidateJournalResolverTest extends TestCase
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

    private PostingCommandCandidateJournalResolver $resolver;

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
        $this->resolver = new PostingCommandCandidateJournalResolver(
            new PostingCommandJournalStateResolver($this->journalRepository),
            new DraftJournalAssembler,
        );
        $this->tenantA = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');

        foreach (['account-cash', 'account-income'] as $accountId) {
            $this->saveAccount($this->tenantA, $accountId);
        }
    }

    /**
     * A fresh, valid, balanced command is assembled successfully via
     * the existing `DraftJournalAssembler` delegation.
     */
    public function test_fresh_valid_command_returns_assembled_journal(): void
    {
        $command = $this->makeCommand(JournalId::of('journal-fresh'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $journal = $this->resolver->resolve($command);

        $this->assertTrue($journal->isBalanced());
        $this->assertSame('journal-fresh', $journal->id()->toString());
    }

    /**
     * (POST-T044) A fresh identity accompanied by an incomplete line
     * payload (fewer than two lines) fails through the existing
     * fresh-assembly validation path — the same
     * `InsufficientJournalLinesException` `DraftJournalAssembler`
     * already throws, propagated unchanged.
     */
    public function test_fresh_command_with_insufficient_lines_propagates_unchanged(): void
    {
        $this->expectException(InsufficientJournalLinesException::class);

        $this->resolver->resolve($this->makeCommand(JournalId::of('journal-fresh'), [
            $this->debitLine('account-cash', '100.00'),
        ]));
    }

    /**
     * (POST-T044) A fresh identity accompanied by a mixed-Currency
     * line payload fails through the same existing assembly path.
     */
    public function test_fresh_command_with_mixed_currency_propagates_unchanged(): void
    {
        $otherCurrency = $this->currencyOtherThanMyr();

        $this->expectException(MixedCurrencyJournalException::class);

        $this->resolver->resolve($this->makeCommand(JournalId::of('journal-fresh'), [
            $this->debitLine('account-cash', '100.00'),
            JournalLine::create(AccountId::of('account-income'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Credit),
        ]));
    }

    /**
     * (POST-T044) A fresh identity accompanied by an unbalanced line
     * payload fails through the same existing assembly path.
     */
    public function test_fresh_command_with_unbalanced_lines_propagates_unchanged(): void
    {
        $this->expectException(UnbalancedJournalException::class);

        $this->resolver->resolve($this->makeCommand(JournalId::of('journal-fresh'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '99.99'),
        ]));
    }

    /**
     * An existing Draft Journal is returned exactly as persisted — the
     * same instance the underlying state resolver already resolves.
     */
    public function test_existing_draft_returns_the_exact_persisted_journal(): void
    {
        $persisted = $this->saveDraftJournal('journal-0001', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $journal = $this->resolver->resolve($this->makeCommand(JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));

        $this->assertTrue($persisted->equals($journal));
        $this->assertTrue($journal->tenantId()->equals($this->tenantA));
        $this->assertCount(2, $journal->lines());
    }

    /**
     * An existing Draft's command lines are NOT used to rebuild or
     * mutate that Journal: even when the command supplies a
     * completely different line set than what is persisted, the
     * returned Journal still carries exactly the persisted lines,
     * unchanged.
     */
    public function test_existing_draft_command_lines_are_not_used_to_rebuild_the_journal(): void
    {
        $this->saveDraftJournal('journal-0002', [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $commandWithDifferentLines = $this->makeCommand(JournalId::of('journal-0002'), [
            $this->debitLine('account-cash', '999.00'),
            $this->creditLine('account-income', '999.00'),
        ]);

        $journal = $this->resolver->resolve($commandWithDifferentLines);

        $this->assertSame('100.00', $journal->lines()[0]->money()->toDecimalString());
        $this->assertSame('100.00', $journal->lines()[1]->money()->toDecimalString());
    }

    /**
     * An existing Posted Journal remains rejected — inherited
     * unchanged from the underlying `PostingCommandJournalStateResolver`.
     */
    public function test_existing_posted_journal_remains_rejected(): void
    {
        $posted = Journal::create($this->tenantA, JournalId::of('journal-posted'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ], $this->financialDate())->post($this->postedAt());
        $this->journalRepository->save($posted);

        $this->expectException(RejectedJournalStateException::class);

        $this->resolver->resolve($this->makeCommand(JournalId::of('journal-posted'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]));
    }

    /**
     * This class performs no persistence, no transaction, and no
     * mutation of any kind — no `->save(`, `->post(`, or `DB::`/
     * transaction call anywhere in its source.
     */
    public function test_performs_no_persistence_transaction_or_mutation(): void
    {
        $reflection = new ReflectionClass(PostingCommandCandidateJournalResolver::class);
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
