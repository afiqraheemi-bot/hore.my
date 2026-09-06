<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidCurrencyException;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Journal\Exception\DuplicateJournalIdentityException;
use App\Infrastructure\Accounting\Journal\Exception\ImmutableJournalStateException;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Infrastructure\Accounting\ChartOfAccounts\AccountRepositoryIntegrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for {@see JournalRepository} (M3-T10),
 * exercised against a real PostgreSQL instance and the real production
 * `journals`/`journal_lines`/`accounts` migrations — never SQLite as
 * evidence of atomicity, immutability, or tenant isolation, mirroring
 * the precedent already established by
 * {@see AccountRepositoryIntegrationTest}.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class JournalRepositoryIntegrationTest extends TestCase
{
    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private JournalRepository $repository;

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

        $this->repository = new JournalRepository(DB::connection('pgsql'));
        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        foreach (['account-cash', 'account-income', 'account-expense', 'account-other', 'account-a', 'account-b', 'account-c'] as $accountId) {
            $this->insertAccount($this->tenantA, $accountId);
        }
        // account_id is the accounts table's own primary key (M2-T8.1),
        // not composite with tenant_id — these must be globally
        // distinct from tenantA's account identifiers above.
        foreach (['account-cash-b', 'account-income-b'] as $accountId) {
            $this->insertAccount($this->tenantB, $accountId);
        }
    }

    // (1) save new Draft Journal.
    public function test_save_persists_a_new_draft_journal(): void
    {
        $journal = $this->makeJournal();

        $this->repository->save($journal);

        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(2, DB::connection('pgsql')->table(self::LINE_TABLE)->count());
        $this->assertSame('Draft', $this->fetchRawHeader('journal-0001')['state']);
    }

    // (2) findById exact Draft round-trip.
    public function test_find_by_id_returns_an_exact_draft_round_trip(): void
    {
        $journal = $this->makeJournal();
        $this->repository->save($journal);

        $found = $this->repository->findById($this->tenantA, JournalId::of('journal-0001'));

        $this->assertNotNull($found);
        $this->assertTrue($journal->equals($found));
        $this->assertSame(JournalState::Draft, $found->state());
        $this->assertTrue($this->tenantA->equals($found->tenantId()));
        $this->assertCount(2, $found->lines());
        $this->assertTrue($journal->lines()[0]->equals($found->lines()[0]));
        $this->assertTrue($journal->lines()[1]->equals($found->lines()[1]));
    }

    /**
     * (3) A Journal that already carries Posted state in memory (a
     * real domain fact, produced only through {@see Journal::post()})
     * can be saved under a brand-new identifier — this is simply
     * persisting already-decided domain state faithfully, exactly as
     * {@see JournalRepository::findById()} must later be able to
     * reconstruct a Posted Journal. It is not a new business posting
     * operation: the repository performs a plain first-time insert,
     * identical in shape to the Draft case, and invents nothing about
     * *how* the Journal came to be Posted.
     */
    public function test_save_persists_a_new_journal_that_is_already_posted(): void
    {
        $journal = $this->makeJournal()->post();

        $this->repository->save($journal);

        $this->assertSame('Posted', $this->fetchRawHeader('journal-0001')['state']);
        $found = $this->repository->findById($this->tenantA, JournalId::of('journal-0001'));
        $this->assertNotNull($found);
        $this->assertSame(JournalState::Posted, $found->state());
    }

    // (4) valid Draft -> Posted persistence succeeds.
    public function test_draft_to_posted_transition_persists_successfully(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);

        $this->repository->save($draft->post());

        $this->assertSame('Posted', $this->fetchRawHeader('journal-0001')['state']);
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
    }

    // (5) exact lines remain unchanged during Draft -> Posted.
    public function test_lines_remain_exactly_unchanged_across_the_draft_to_posted_transition(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);
        $linesBefore = $this->fetchRawLines('journal-0001');

        $this->repository->save($draft->post());

        $this->assertSame($linesBefore, $this->fetchRawLines('journal-0001'));
        $this->assertSame(2, DB::connection('pgsql')->table(self::LINE_TABLE)->count());
    }

    // (6) Posted -> Draft rejected.
    public function test_posted_to_draft_transition_is_rejected(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft->post());
        $before = $this->snapshot('journal-0001');

        $reconstitutedAsDraft = Journal::reconstitute($this->tenantA, JournalId::of('journal-0001'), $draft->lines(), JournalState::Draft);

        $this->assertRejectedWithoutMutatingState($reconstitutedAsDraft, 'journal-0001', $before);
    }

    // (7) Draft with changed AccountId line rejected.
    public function test_draft_with_a_changed_line_account_id_is_rejected(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);
        $before = $this->snapshot('journal-0001');

        $conflicting = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-other', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertRejectedWithoutMutatingState($conflicting, 'journal-0001', $before);
    }

    // (8) Draft with changed amount rejected.
    public function test_draft_with_a_changed_line_amount_is_rejected(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);
        $before = $this->snapshot('journal-0001');

        $conflicting = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '150.00'),
            $this->creditLine('account-income', '150.00'),
        ]);

        $this->assertRejectedWithoutMutatingState($conflicting, 'journal-0001', $before);
    }

    // (9) Draft with changed Currency rejected.
    public function test_draft_with_a_changed_line_currency_is_rejected(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);

        // Currency::of() currently only registers 'MYR' (AETS-003
        // §6), so no second legitimate Currency exists to construct a
        // real conflicting Journal from. Instead, the already-persisted
        // line's own currency is corrupted directly at the row level —
        // exactly like `test_malformed_persisted_state_is_rejected_on_read`
        // already does elsewhere in this suite — so that a subsequent,
        // otherwise-identical legitimate MYR resave disagrees with what
        // is on record and must still be rejected by the repository's
        // own raw-value comparison (which never re-validates through
        // Currency::of() itself).
        DB::connection('pgsql')->table(self::LINE_TABLE)
            ->where('journal_id', 'journal-0001')
            ->where('line_position', 0)
            ->update(['currency' => 'XYZ']);
        $before = $this->snapshot('journal-0001');

        $this->assertRejectedWithoutMutatingState($draft, 'journal-0001', $before);
    }

    // (10) Draft with changed Direction rejected.
    public function test_draft_with_a_changed_line_direction_is_rejected(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);
        $before = $this->snapshot('journal-0001');

        $conflicting = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->creditLine('account-cash', '100.00'),
            $this->debitLine('account-income', '100.00'),
        ]);

        $this->assertRejectedWithoutMutatingState($conflicting, 'journal-0001', $before);
    }

    // (11) Draft with changed line order rejected.
    public function test_draft_with_reordered_lines_is_rejected(): void
    {
        $draft = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-a', '10.00'),
            $this->debitLine('account-b', '20.00'),
            $this->creditLine('account-c', '30.00'),
        ]);
        $this->repository->save($draft);
        $before = $this->snapshot('journal-0001');

        $conflicting = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-b', '20.00'),
            $this->debitLine('account-a', '10.00'),
            $this->creditLine('account-c', '30.00'),
        ]);

        $this->assertRejectedWithoutMutatingState($conflicting, 'journal-0001', $before);
    }

    // (12) Draft with added line rejected.
    public function test_draft_with_an_added_line_is_rejected(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);
        $before = $this->snapshot('journal-0001');

        $conflicting = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-expense', '40.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertRejectedWithoutMutatingState($conflicting, 'journal-0001', $before);
    }

    // (13) Draft with removed line rejected.
    public function test_draft_with_a_removed_line_is_rejected(): void
    {
        $draft = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-expense', '40.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $this->repository->save($draft);
        $before = $this->snapshot('journal-0001');

        $conflicting = $this->makeJournal();

        $this->assertRejectedWithoutMutatingState($conflicting, 'journal-0001', $before);
    }

    // (14) TenantId change for same journal_id rejected.
    public function test_tenant_id_change_for_an_existing_journal_id_is_rejected(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);
        $before = $this->snapshot('journal-0001');

        $conflicting = Journal::create($this->tenantB, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertRejectedWithoutMutatingState($conflicting, 'journal-0001', $before);
    }

    /**
     * (15) Two different kinds of rejected write — a Posted -> Draft
     * reversal attempt and a line-content change attempt — each leave
     * the existing header and lines byte-for-byte identical to what
     * was persisted before either attempt, proving the guard is not a
     * one-shot check that happens to leave things alone the first
     * time.
     */
    public function test_failed_immutable_state_attempts_leave_existing_header_and_lines_unchanged(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft->post());
        $before = $this->snapshot('journal-0001');

        $asDraft = Journal::reconstitute($this->tenantA, JournalId::of('journal-0001'), $draft->lines(), JournalState::Draft);
        $this->assertRejectedWithoutMutatingState($asDraft, 'journal-0001', $before);

        $withDifferentLines = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '999.00'),
            $this->creditLine('account-income', '999.00'),
        ])->post();
        $this->assertRejectedWithoutMutatingState($withDifferentLines, 'journal-0001', $before);
    }

    // (16) tenant-isolated findById.
    public function test_find_by_id_is_tenant_isolated(): void
    {
        $journal = Journal::create($this->tenantB, JournalId::of('journal-shared-id'), [
            $this->debitLine('account-cash-b', '100.00'),
            $this->creditLine('account-income-b', '100.00'),
        ]);
        $this->repository->save($journal);

        $this->assertNull($this->repository->findById($this->tenantA, JournalId::of('journal-shared-id')));
        $this->assertNotNull($this->repository->findById($this->tenantB, JournalId::of('journal-shared-id')));
    }

    /**
     * (M4-T19) `existsById()` is deliberately global — unlike
     * `findById()`, it answers whether a JournalId is reserved by
     * *any* Tenant's Journal, not just this connection's caller's own.
     */
    public function test_exists_by_id_is_true_for_a_journal_owned_by_any_tenant(): void
    {
        $journal = Journal::create($this->tenantB, JournalId::of('journal-owned-by-b'), [
            $this->debitLine('account-cash-b', '100.00'),
            $this->creditLine('account-income-b', '100.00'),
        ]);
        $this->repository->save($journal);

        $this->assertTrue($this->repository->existsById(JournalId::of('journal-owned-by-b')));
    }

    public function test_exists_by_id_is_false_for_a_genuinely_unused_identity(): void
    {
        $this->assertFalse($this->repository->existsById(JournalId::of('journal-never-used')));
    }

    /**
     * `existsById()` returns a plain `bool` — there is no way, by its
     * return type alone, for a caller to extract a Tenant, a state, or
     * any other identifying detail about the Journal it found.
     */
    public function test_exists_by_id_returns_a_plain_boolean(): void
    {
        $journal = Journal::create($this->tenantA, JournalId::of('journal-0099'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $this->repository->save($journal);

        $result = $this->repository->existsById(JournalId::of('journal-0099'));

        $this->assertIsBool($result);
    }

    // (17) exact line order on retrieval.
    public function test_find_by_id_preserves_exact_line_order(): void
    {
        $journal = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-a', '10.00'),
            $this->debitLine('account-b', '20.00'),
            $this->creditLine('account-c', '30.00'),
        ]);
        $this->repository->save($journal);

        $found = $this->repository->findById($this->tenantA, JournalId::of('journal-0001'));
        $this->assertNotNull($found);

        $accountIds = array_map(
            static fn (JournalLine $line): string => $line->accountId()->toString(),
            $found->lines(),
        );
        $this->assertSame(['account-a', 'account-b', 'account-c'], $accountIds);
    }

    /**
     * (18) exact BIGINT amount round-trip — proof this specifically
     * requires a real database, since a PHP-only double could silently
     * lose precision at this magnitude if float were ever involved.
     */
    public function test_a_large_exact_bigint_amount_round_trips_with_no_precision_loss(): void
    {
        $journal = Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '92233720368547.75'),
            $this->creditLine('account-income', '92233720368547.75'),
        ]);
        $this->repository->save($journal);

        $found = $this->repository->findById($this->tenantA, JournalId::of('journal-0001'));
        $this->assertNotNull($found);

        $this->assertSame('92233720368547.75', $found->lines()[0]->money()->toDecimalString());
        $this->assertSame('92233720368547.75', $found->lines()[1]->money()->toDecimalString());
    }

    /**
     * (19) A row that satisfies every database-level constraint can
     * still carry a value one of the Value Objects' own validation
     * rejects (here, an unsupported Currency identifier). The
     * repository must not swallow that rejection: it propagates from
     * {@see JournalPersistenceAdapter::fromPersistedJournal()}
     * unmodified.
     */
    public function test_malformed_persisted_state_is_rejected_on_read(): void
    {
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->insert([
            'tenant_id' => $this->tenantA->toString(),
            'journal_id' => 'journal-malformed',
            'state' => 'Draft',
        ]);
        DB::connection('pgsql')->table(self::LINE_TABLE)->insert([
            ['tenant_id' => $this->tenantA->toString(), 'journal_id' => 'journal-malformed', 'line_position' => 0, 'account_id' => 'account-cash', 'amount' => 10000, 'currency' => 'BADCUR', 'direction' => 'Debit'],
            ['tenant_id' => $this->tenantA->toString(), 'journal_id' => 'journal-malformed', 'line_position' => 1, 'account_id' => 'account-income', 'amount' => 10000, 'currency' => 'BADCUR', 'direction' => 'Credit'],
        ]);

        $this->expectException(InvalidCurrencyException::class);

        $this->repository->findById($this->tenantA, JournalId::of('journal-malformed'));
    }

    // (20) no delete API.
    public function test_no_delete_api_exists(): void
    {
        $reflection = new \ReflectionClass(JournalRepository::class);
        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => strtolower($method->getName()),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach (['delete', 'remove', 'destroy', 'purge'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * Atomic rollback: a domain-valid Journal can still reference a
     * nonexistent Account — {@see AccountId} is an opaque Value
     * Object the Domain layer cannot check for real existence, so this
     * is a genuine, unavoidable real-database fault, not an invented
     * one. It fires from the real `journal_lines_tenant_id_account_id_foreign`
     * composite foreign key, naturally mid-transaction: after the
     * header insert, during the line insert. The whole transaction
     * — including the header — must roll back; zero rows of either
     * table may remain (mirrors `JRN-T083`/`JRN-T030`'s intent, at the
     * repository rather than Posting Command level — see the final
     * report's traceability-gap note).
     */
    public function test_a_fault_injected_during_the_line_insert_rolls_back_the_entire_transaction(): void
    {
        $journal = Journal::create($this->tenantA, JournalId::of('journal-atomic'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-does-not-exist', '100.00'),
        ]);

        try {
            $this->repository->save($journal);
            $this->fail('Expected the composite foreign key on journal_lines to reject a nonexistent Account.');
        } catch (QueryException $e) {
            // Expected: real FK violation, mid-transaction.
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-atomic')->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-atomic')->count());
    }

    /**
     * Concurrency: two genuinely independent PostgreSQL connections.
     * Connection A locks the existing Journal row (`SELECT ... FOR
     * UPDATE` inside an open transaction) during what would be its own
     * save/update flow; connection B, with a short `lock_timeout`,
     * attempts a conflicting Draft -> Posted `save()` for the same
     * Journal and must block, then fail, rather than silently racing
     * past connection A's in-flight work. This proves, together: two
     * competing saves cannot silently overwrite each other; a
     * conflicting writer blocks/fails rather than mutating authoritative
     * history; and concurrent Draft -> Posted attempts cannot produce
     * duplicate lines or conflicting state (no attempted write can even
     * reach the point of comparing or writing lines while the lock is
     * held). Mirrors {@see AccountRepositoryIntegrationTest::test_concurrent_conflicting_writes_do_not_silently_overwrite_immutable_state()}'s
     * already-established technique.
     */
    public function test_concurrent_conflicting_saves_do_not_silently_overwrite_authoritative_state(): void
    {
        $draft = $this->makeJournal();
        $this->repository->save($draft);
        $before = $this->snapshot('journal-0001');

        config(['database.connections.pgsql_secondary' => config('database.connections.pgsql')]);
        DB::purge('pgsql_secondary');
        $secondConnection = DB::connection('pgsql_secondary');
        $secondConnection->statement("set lock_timeout = '200ms'");
        $secondConnection->statement("set statement_timeout = '2000ms'");
        $secondRepository = new JournalRepository($secondConnection);

        $firstConnection = DB::connection('pgsql');
        $firstConnection->beginTransaction();
        $firstConnection->table(self::JOURNAL_TABLE)
            ->where('journal_id', 'journal-0001')
            ->lockForUpdate()
            ->first();

        try {
            $secondRepository->save($draft->post());
            $this->fail('Expected the concurrent save() to block on the row lock and then fail.');
        } catch (QueryException $e) {
            // Expected: the second connection could not acquire the row
            // lock within its lock_timeout, proving it was genuinely
            // blocked by the first connection's open transaction rather
            // than racing past it.
        } finally {
            $firstConnection->rollBack();
            DB::purge('pgsql_secondary');
        }

        $this->assertSame($before, $this->snapshot('journal-0001'));
    }

    /**
     * (M4-T18B) A genuine race between two *fresh* inserts of the same
     * JournalId — the case `save()`'s own existing-row lock cannot
     * cover, since both sides observe no existing row before either
     * commits. Reaching a real (non-blocking) duplicate-key error at
     * the actual `INSERT` statement, rather than a mere lock timeout,
     * requires the winning transaction to genuinely commit while the
     * losing one is still in-flight — impossible to sequence
     * deterministically with two connections in one process (the
     * technique every other concurrency test in this suite already
     * uses only ever proves *blocking*, never resolves it into a real
     * duplicate-key error). A forked child process gives the winner an
     * independent execution timeline that can commit mid-flight,
     * exactly the smallest mechanism available for this specific case.
     */
    public function test_fresh_duplicate_journal_identity_is_translated_into_a_focused_exception(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('The pcntl and posix extensions are required to genuinely race two fresh inserts of the same JournalId.');
        }

        $journalId = 'journal-race-identity';
        $readyMarker = sys_get_temp_dir().'/journal-race-ready-'.$journalId;
        @unlink($readyMarker);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            // A forked child's inherited STDOUT/STDERR, still shared
            // with the parent's own pipe, has been observed to confuse
            // the test runner's own output handling. Closing them
            // prevents that — but the freed fd slots (1, 2) must be
            // reclaimed immediately, or a subsequent socket (this
            // child's own fresh Postgres connection) can silently be
            // assigned one of them instead.
            fclose(STDOUT);
            fclose(STDERR);
            $devNullOut = fopen('/dev/null', 'w');
            $devNullErr = fopen('/dev/null', 'w');

            // Everything below MUST reach the final `posix_kill()`, no
            // matter what — this is a full fork of the running PHPUnit
            // process, so any exception left to propagate uncaught here
            // would fall through to PHPUnit's own exception-to-failure
            // handling within this copy, corrupting or duplicating this
            // test's own result reporting. A plain `exit()` has the same
            // problem via PHP's normal shutdown sequence, which also
            // re-runs PHPUnit's inherited shutdown hooks. Only a direct
            // signal bypasses all of that.
            try {
                // An independent connection (never the parent's
                // inherited, already-open socket) holds a fresh,
                // uncommitted insert open for a bounded window, then
                // commits — giving the parent's own blocked insert a
                // real, committed conflict to discover once it unblocks.
                // `save()` opens its own transaction internally, which
                // would otherwise commit immediately on return — this
                // explicit outer transaction is what actually holds the
                // row open across the sleep below.
                DB::purge('pgsql');
                $connection = DB::connection('pgsql');
                $connection->beginTransaction();
                (new JournalRepository($connection))->save(Journal::create($this->tenantA, JournalId::of($journalId), [
                    $this->debitLine('account-cash', '100.00'),
                    $this->creditLine('account-income', '100.00'),
                ]));
                // Only now does the parent below know it is safe to
                // attempt its own insert — without this explicit
                // handshake, which side inserts first is an unbounded
                // race against process-scheduling and connection-setup
                // overhead, not a deterministic test.
                file_put_contents($readyMarker, '1');
                usleep(2_000_000);
                $connection->commit();
            } catch (\Throwable) {
                // Deliberately swallowed — see above.
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        // Parent: the loser. Waits for the child's own explicit signal
        // that its insert is in place before attempting the same
        // JournalId — never a fixed sleep guessing at relative timing.
        $this->waitForMarker($readyMarker);

        config(['database.connections.pgsql_secondary' => config('database.connections.pgsql')]);
        DB::purge('pgsql_secondary');
        $secondConnection = DB::connection('pgsql_secondary');
        $secondConnection->statement("set lock_timeout = '10000ms'");
        $secondRepository = new JournalRepository($secondConnection);

        try {
            $secondRepository->save(Journal::create($this->tenantA, JournalId::of($journalId), [
                $this->debitLine('account-cash', '250.00'),
                $this->creditLine('account-income', '250.00'),
            ]));
            $this->fail('Expected a genuine duplicate Journal identity violation.');
        } catch (DuplicateJournalIdentityException $e) {
            $this->assertStringContainsString($journalId, $e->getMessage());
        } finally {
            $this->waitForChild($pid);
            DB::purge('pgsql_secondary');
        }

        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', $journalId)->count());
    }

    /**
     * Bounded wait for a forked child's own explicit readiness signal —
     * never a fixed sleep guessing at relative process-scheduling
     * timing, and never an indefinite wait if the child never signals.
     */
    private function waitForMarker(string $path): void
    {
        for ($i = 0; $i < 100; $i++) {
            if (file_exists($path)) {
                return;
            }

            usleep(50_000);
        }

        $this->fail("Timed out waiting for the forked child's readiness marker: {$path}");
    }

    /**
     * Bounded reap of a forked child — never an indefinite
     * `pcntl_waitpid()` block. The child in
     * {@see test_fresh_duplicate_journal_identity_is_translated_into_a_focused_exception()}
     * always exits promptly on its own; this only guards against that
     * assumption ever silently becoming false.
     */
    private function waitForChild(int $pid): void
    {
        for ($i = 0; $i < 80; $i++) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);

            if ($result !== 0) {
                return;
            }

            usleep(100_000);
        }

        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }

    /**
     * @return array{header: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function snapshot(string $journalId): array
    {
        return [
            'header' => $this->fetchRawHeader($journalId),
            'lines' => $this->fetchRawLines($journalId),
        ];
    }

    /**
     * @param  array{header: array<string, mixed>, lines: list<array<string, mixed>>}  $expectedUnchanged
     */
    private function assertRejectedWithoutMutatingState(Journal $conflicting, string $journalId, array $expectedUnchanged): void
    {
        try {
            $this->repository->save($conflicting);
            $this->fail('Expected ImmutableJournalStateException to be thrown.');
        } catch (ImmutableJournalStateException $e) {
            // expected
        }

        $this->assertSame($expectedUnchanged, $this->snapshot($journalId));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRawHeader(string $journalId): array
    {
        /** @var object{tenant_id: string, journal_id: string, state: string} $row */
        $row = DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', $journalId)->firstOrFail();

        return [
            'tenant_id' => $row->tenant_id,
            'journal_id' => $row->journal_id,
            'state' => $row->state,
        ];
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
            ->map(static fn (object $row): array => [
                'tenant_id' => $row->tenant_id,
                'journal_id' => $row->journal_id,
                'line_position' => (int) $row->line_position,
                'account_id' => $row->account_id,
                'amount' => (string) $row->amount,
                'currency' => $row->currency,
                'direction' => $row->direction,
            ])
            ->all();
    }

    private function makeJournal(): Journal
    {
        return Journal::create($this->tenantA, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    private function insertAccount(TenantId $tenantId, string $accountId): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId->toString().$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => 'Asset',
            'account_origin' => 'UserCreated',
            'active' => true,
            'posting_eligible' => true,
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

        // `migrate:rollback --path=X` only rolls back the most recent
        // *batch*, using `--path` merely to filter which files within
        // that batch are eligible — it does not target a specific
        // migration regardless of batch. Dropping the tables directly
        // and clearing their tracking rows is unambiguous regardless
        // of batch history (same fix established in M3-T9).
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

        self::forceCleanMigration(self::JOURNAL_MIGRATION_PATH, [self::LINE_TABLE, self::JOURNAL_TABLE]);
        self::forceCleanMigration(self::CORRECTION_MIGRATION_PATH, []);

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
