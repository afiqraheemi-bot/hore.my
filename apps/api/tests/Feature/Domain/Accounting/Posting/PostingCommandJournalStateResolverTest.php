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
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\RejectedJournalStateException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for `PostingCommandJournalStateResolver`
 * (M4-T9), exercised against a real PostgreSQL instance and the real
 * production `journals`/`journal_lines`/`accounts` migrations — never
 * SQLite, mirroring the precedent established for
 * `PostingCommandAccountValidatorTest` (M4-T8). This resolver composes
 * `JournalRepository` directly, so its tenant-scoped lookup behavior
 * must be proven for real.
 *
 * Direct coverage for `POST-T041`–`POST-T043` only. `POST-T044`
 * (malformed: identity not found and not accompanied by a complete
 * fresh Journal Line set) is deliberately *not* claimed here — this
 * resolver never inspects a command's lines at all, so it cannot
 * itself evidence that scenario; doing so would require crossing into
 * `DraftJournalAssembler`, which this task does not do. `POST-T045`
 * (line order preserved) remains evidenced by earlier tests
 * (M4-T6/M4-T7) and is not reimplemented here.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingCommandJournalStateResolverTest extends TestCase
{
    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private PostingCommandJournalStateResolver $resolver;

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

        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $this->journalRepository = new JournalRepository(DB::connection('pgsql'));
        $this->resolver = new PostingCommandJournalStateResolver($this->journalRepository);
        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        foreach (['account-cash', 'account-income'] as $accountId) {
            $this->saveAccount($this->tenantA, $accountId);
        }
        $this->saveAccount($this->tenantB, 'account-cash-b');
    }

    /**
     * (POST-T041) No Journal is persisted under the command's proposed
     * identity for this Tenant — the identity resolves as fresh.
     */
    public function test_missing_journal_id_resolves_as_fresh(): void
    {
        $command = $this->makeCommand($this->tenantA, JournalId::of('journal-fresh'));

        $result = $this->resolver->resolve($command);

        $this->assertTrue($result->isFresh());
        $this->assertNull($result->existingDraftJournal());
    }

    /**
     * (POST-T042) An existing Draft Journal under the command's
     * proposed identity resolves successfully, carrying that exact
     * Journal.
     */
    public function test_existing_draft_journal_resolves_successfully(): void
    {
        $draft = $this->saveDraftJournal('journal-0001');

        $result = $this->resolver->resolve($this->makeCommand($this->tenantA, JournalId::of('journal-0001')));

        $this->assertFalse($result->isFresh());
        $this->assertNotNull($result->existingDraftJournal());
        $this->assertTrue($draft->equals($result->existingDraftJournal()));
    }

    /**
     * The exact existing Draft identity (Tenant, JournalId, state) is
     * preserved through resolution — unmodified, as persisted.
     */
    public function test_exact_existing_draft_identity_is_preserved(): void
    {
        $this->saveDraftJournal('journal-0002');

        $result = $this->resolver->resolve($this->makeCommand($this->tenantA, JournalId::of('journal-0002')));

        $resolved = $result->existingDraftJournal();
        $this->assertNotNull($resolved);
        $this->assertTrue($this->tenantA->equals($resolved->tenantId()));
        $this->assertSame('journal-0002', $resolved->id()->toString());
        $this->assertSame(JournalState::Draft, $resolved->state());
    }

    /**
     * (POST-T043) An existing Posted Journal under the command's
     * proposed identity is rejected — Draft-only candidate input.
     */
    public function test_existing_posted_journal_is_rejected(): void
    {
        $this->savePostedJournal('journal-posted');

        $this->expectException(RejectedJournalStateException::class);

        $this->resolver->resolve($this->makeCommand($this->tenantA, JournalId::of('journal-posted')));
    }

    /**
     * A Journal belonging to a different Tenant than the command
     * remains observationally equivalent to "not found" — the
     * existing `JournalRepository::findById()` contract is
     * tenant-scoped, and this resolver introduces no second,
     * tenant-unscoped lookup to tell the two apart.
     */
    public function test_wrong_tenant_journal_resolves_as_fresh(): void
    {
        $tenantBJournal = Journal::create($this->tenantB, JournalId::of('journal-shared-id'), [
            $this->debitLine('account-cash-b', '100.00'),
            $this->creditLine('account-cash-b', '100.00'),
        ]);
        $this->journalRepository->save($tenantBJournal);

        $result = $this->resolver->resolve($this->makeCommand($this->tenantA, JournalId::of('journal-shared-id')));

        $this->assertTrue($result->isFresh());
    }

    /**
     * Resolving performs no mutation and no persistence: the row
     * count and state of every Journal remain exactly what they were
     * before resolution, for both the fresh and existing-Draft cases.
     */
    public function test_resolution_performs_no_mutation_or_persistence(): void
    {
        $this->saveDraftJournal('journal-0003');
        $beforeJournalCount = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count();
        $beforeState = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-0003')->value('state');

        $this->resolver->resolve($this->makeCommand($this->tenantA, JournalId::of('journal-0003')));
        $this->resolver->resolve($this->makeCommand($this->tenantA, JournalId::of('journal-never-persisted')));

        $this->assertSame($beforeJournalCount, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame($beforeState, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-0003')->value('state'));
        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-never-persisted')->count());
    }

    /**
     * The resolver never calls `Journal::post()` or
     * `JournalRepository::save()`, and never delegates to
     * `DraftJournalAssembler` — it is a pure, read-only state gate.
     * The class's own docblock legitimately *names*
     * `DraftJournalAssembler` in prose, to explain that it is
     * deliberately not called from here — mirroring the established
     * pattern of `AccountRepositoryTest::test_does_not_run_hierarchy_policy_on_a_single_read()`.
     * What must be absent is any actual *use*: an import or a call.
     */
    public function test_never_posts_saves_or_delegates_to_the_assembler(): void
    {
        $reflection = new \ReflectionClass(PostingCommandJournalStateResolver::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->post(', $source);
        $this->assertStringNotContainsString('->save(', $source);
        $this->assertStringNotContainsString('use App\\Domain\\Accounting\\Posting\\DraftJournalAssembler', $source);
        $this->assertStringNotContainsString('new DraftJournalAssembler', $source);
        $this->assertStringNotContainsString('DraftJournalAssembler::', $source);
    }

    private function saveDraftJournal(string $journalId): Journal
    {
        $journal = Journal::create($this->tenantA, JournalId::of($journalId), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $this->journalRepository->save($journal);

        return $journal;
    }

    private function savePostedJournal(string $journalId): Journal
    {
        $journal = Journal::create($this->tenantA, JournalId::of($journalId), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ])->post();
        $this->journalRepository->save($journal);

        return $journal;
    }

    private function makeCommand(TenantId $tenantId, JournalId $journalId): PostingCommand
    {
        return new PostingCommand(
            IdempotencyKey::of('key-0001'),
            $tenantId,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            $journalId,
            [
                $this->debitLine('account-cash', '100.00'),
                $this->creditLine('account-income', '100.00'),
            ],
        );
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

        self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);

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
