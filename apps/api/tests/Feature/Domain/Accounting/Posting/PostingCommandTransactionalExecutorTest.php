<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Accounting\Posting;

use App\Domain\Accounting\Audit\AuditAction;
use App\Domain\Accounting\Audit\AuditEvent;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
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
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\Exception\RejectedJournalIdentityUnavailableException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommand;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\PostingCommandExistingDraftLineValidator;
use App\Domain\Accounting\Posting\PostingCommandIdempotencyResolver;
use App\Domain\Accounting\Posting\PostingCommandJournalExecutor;
use App\Domain\Accounting\Posting\PostingCommandJournalStateResolver;
use App\Domain\Accounting\Posting\PostingCommandLogicalEquivalence;
use App\Domain\Accounting\Posting\PostingCommandPeriodLockValidator;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Period\PeriodClosureRepository;
use App\Infrastructure\Accounting\Posting\JournalEvidenceLinkRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for {@see PostingCommandTransactionalExecutor}
 * (M4-T18), exercised against a real PostgreSQL instance — every
 * collaborator it composes (`JournalRepository`,
 * `PostingIdempotencyRepository`, `PostingCommandIdempotencyResolver`,
 * `PostingCommandJournalExecutor`) is real, never stubbed, since this
 * class's whole job is atomic composition of their real behavior.
 *
 * Directly evidences `POST-T017`–`T024`, `POST-T066`, `POST-T067`,
 * `POST-T069`, and the loser-recovery half of `POST-T103`/`POST-T104`.
 * It does **not** evidence `POST-T070`/`POST-T127`'s full atomic-posting
 * requirement (Evidence linkage, Audit Event, Outbox event are entirely
 * absent), full LED-003, Source Fingerprint deduplication, or
 * Actor/Tenant resolution — none of that exists yet.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class PostingCommandTransactionalExecutorTest extends TestCase
{
    private const IDEMPOTENCY_TABLE = 'posting_idempotency_keys';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const IDEMPOTENCY_MIGRATION_PATH = 'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const FINANCIAL_DATE_MIGRATION_PATH = 'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const EVIDENCE_LINK_TABLE = 'journal_evidence_links';

    private const AUDIT_EVENT_MIGRATION_PATH = 'database/migrations/2026_09_06_200000_create_audit_events_table.php';

    private const EVIDENCE_LINK_MIGRATION_PATH = 'database/migrations/2026_09_06_210000_create_journal_evidence_links_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private PostingCommandTransactionalExecutor $executor;

    private JournalRepository $journalRepository;

    private PostingIdempotencyRepository $idempotencyRepository;

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

        DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->delete();
        DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->delete();
        DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->delete();
        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $connection = DB::connection('pgsql');
        $this->journalRepository = new JournalRepository($connection);
        $this->idempotencyRepository = new PostingIdempotencyRepository($connection);
        $this->executor = $this->buildExecutor($connection);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-cash');
        $this->insertAccount($this->tenantA, 'account-income');
        $this->insertAccount($this->tenantB, 'account-cash-b');
        $this->insertAccount($this->tenantB, 'account-income-b');
    }

    // --- Sequential: first submission ------------------------------------

    public function test_fresh_key_produces_a_newly_posted_result(): void
    {
        $result = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines()));

        $this->assertTrue($result->isNewlyPosted());
        $this->assertFalse($result->isReplay());
    }

    public function test_newly_posted_journal_is_in_posted_state(): void
    {
        $result = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines()));

        $this->assertSame(JournalState::Posted, $result->journal()->state());
    }

    public function test_newly_posted_journal_reloads_as_posted(): void
    {
        $result = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines()));

        $reloaded = $this->journalRepository->findById($this->tenantA, $result->journal()->id());

        $this->assertNotNull($reloaded);
        $this->assertSame(JournalState::Posted, $reloaded->state());
    }

    public function test_newly_posted_records_a_mapping_to_the_exact_journal_id(): void
    {
        $key = IdempotencyKey::of('key-0001');
        $result = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));

        $mapped = $this->idempotencyRepository->find($this->tenantA, $key);

        $this->assertNotNull($mapped);
        $this->assertTrue($mapped->equals($result->journal()->id()));
    }

    // --- Sequential: exact replay -----------------------------------------

    public function test_exact_retry_returns_a_replay_result(): void
    {
        $key = IdempotencyKey::of('key-0001');
        $first = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));

        $second = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));

        $this->assertTrue($second->isReplay());
        $this->assertFalse($second->isNewlyPosted());
        $this->assertTrue($second->journal()->id()->equals($first->journal()->id()));
    }

    public function test_exact_retry_does_not_insert_a_second_journal(): void
    {
        $key = IdempotencyKey::of('key-0001');
        $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));
        $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));

        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
    }

    public function test_exact_retry_does_not_insert_a_second_mapping(): void
    {
        $key = IdempotencyKey::of('key-0001');
        $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));
        $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));

        $this->assertSame(1, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    // --- Sequential: conflicting reuse -------------------------------------

    public function test_conflicting_journal_id_is_rejected_and_original_unchanged(): void
    {
        $key = IdempotencyKey::of('key-0001');
        $first = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));

        try {
            $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-9999'), $this->balancedLines(), idempotencyKey: $key));
            $this->fail('Expected a conflicting-reuse rejection.');
        } catch (RejectedConflictingIdempotencyReuseException) {
            // Expected.
        }

        $unchanged = $this->journalRepository->findById($this->tenantA, $first->journal()->id());
        $this->assertNotNull($unchanged);
        $this->assertSame(JournalState::Posted, $unchanged->state());
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
    }

    public function test_conflicting_amount_is_rejected_and_original_unchanged(): void
    {
        $key = IdempotencyKey::of('key-0001');
        $first = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));

        try {
            $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), [
                $this->debitLine('account-cash', '150.00'),
                $this->creditLine('account-income', '150.00'),
            ], idempotencyKey: $key));
            $this->fail('Expected a conflicting-reuse rejection.');
        } catch (RejectedConflictingIdempotencyReuseException) {
            // Expected.
        }

        $unchanged = $this->journalRepository->findById($this->tenantA, $first->journal()->id());
        $this->assertNotNull($unchanged);
        $this->assertSame(JournalState::Posted, $unchanged->state());
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
    }

    // --- Sequential: key/tenant independence --------------------------------

    public function test_different_key_produces_an_independent_new_posting(): void
    {
        $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: IdempotencyKey::of('key-k1')));

        $second = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0002'), $this->balancedLines(), idempotencyKey: IdempotencyKey::of('key-k2')));

        $this->assertTrue($second->isNewlyPosted());
        $this->assertSame(2, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(2, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    public function test_same_literal_key_under_different_tenant_is_independent(): void
    {
        $key = IdempotencyKey::of('key-shared');

        $forA = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-0001'), $this->balancedLines(), idempotencyKey: $key));

        // `journals.journal_id` is a global primary key, not composite
        // with `tenant_id` — a different Tenant sharing the same
        // literal Idempotency Key must still propose its own distinct
        // JournalId.
        $forB = $this->executor->execute($this->makeCommand(
            $this->tenantB,
            JournalId::of('journal-0002'),
            [$this->debitLine('account-cash-b', '100.00'), $this->creditLine('account-income-b', '100.00')],
            idempotencyKey: $key,
        ));

        $this->assertTrue($forA->isNewlyPosted());
        $this->assertTrue($forB->isNewlyPosted());
        $this->assertFalse($forA->journal()->id()->equals($forB->journal()->id()));
        $this->assertSame(2, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(2, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    // --- M4-T19: cross-tenant JournalId collision ---------------------------

    /**
     * A command proposing a JournalId already owned by a different
     * Tenant is rejected outright — at the pre-validation
     * `PostingCommandJournalStateResolver` step, before any transaction
     * even opens. Critically, `RejectedJournalIdentityUnavailableException`
     * is *not* one of the two exceptions
     * {@see PostingCommandTransactionalExecutor::execute()} treats as a
     * recoverable race — it propagates straight through, exactly like
     * any other validation failure, and never reaches
     * `resolveAfterLostRace()`. This is what actually closes the gap:
     * before M4-T19, this exact scenario would instead reach
     * `JournalRepository::save()`'s own `INSERT`, collide with the
     * other Tenant's real row, and surface as
     * `DuplicateJournalIdentityException` — the concurrency-race
     * signal — triggering an irrelevant re-resolution against this
     * Tenant's own (Tenant, Idempotency Key) mapping.
     */
    public function test_cross_tenant_journal_identity_collision_is_rejected_before_any_transaction(): void
    {
        $tenantBJournal = $this->executor->execute($this->makeCommand(
            $this->tenantB,
            JournalId::of('journal-owned-by-b'),
            [$this->debitLine('account-cash-b', '100.00'), $this->creditLine('account-income-b', '100.00')],
        ));
        $this->assertTrue($tenantBJournal->isNewlyPosted());

        $command = $this->makeCommand($this->tenantA, JournalId::of('journal-owned-by-b'), $this->balancedLines());

        try {
            $this->executor->execute($command);
            $this->fail('Expected a RejectedJournalIdentityUnavailableException.');
        } catch (RejectedJournalIdentityUnavailableException $e) {
            $this->assertStringNotContainsString($this->tenantB->toString(), $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('tenant', $e->getMessage());
        }

        // No *new* Journal or mapping for Tenant A's rejected attempt —
        // exactly the one Journal and one mapping Tenant B's own
        // earlier, successful posting already produced, unchanged.
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
        $reloaded = $this->journalRepository->findById($this->tenantB, JournalId::of('journal-owned-by-b'));
        $this->assertNotNull($reloaded);
        $this->assertTrue($tenantBJournal->journal()->equals($reloaded));
    }

    // --- Atomic rollback ----------------------------------------------------

    /**
     * Forces `PostingIdempotencyRepository::record()`'s insert to fail
     * for a reason *other* than a duplicate key — a temporary `CHECK`
     * constraint that always evaluates false, the smallest available
     * technique for forcing a real, non-duplicate constraint failure at
     * exactly this step, since `record()`'s insert has no other
     * naturally reachable failure mode in an otherwise-valid first
     * submission (its foreign key is always satisfied here, since the
     * Journal it references was just inserted, uncommitted, in the same
     * transaction). This is not `DuplicatePostingIdempotencyKeyException`,
     * so it propagates through {@see PostingCommandTransactionalExecutor::execute()}
     * completely unmodified — proving, by direct database inspection
     * afterward, that the whole outer transaction (Journal header,
     * Journal Lines, and the mapping insert attempt together) rolled
     * back as one unit.
     */
    public function test_forced_idempotency_record_failure_rolls_back_the_entire_transaction(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement(
            'ALTER TABLE posting_idempotency_keys ADD CONSTRAINT force_test_insert_failure CHECK (1 = 0)'
        );

        try {
            try {
                $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-atomic'), $this->balancedLines()));
                $this->fail('Expected the forced CHECK constraint to reject the mapping insert.');
            } catch (QueryException) {
                // Expected: a non-duplicate constraint violation,
                // propagated unmodified.
            }
        } finally {
            $connection->statement('ALTER TABLE posting_idempotency_keys DROP CONSTRAINT force_test_insert_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-atomic')->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-atomic')->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    /**
     * The M6 counterpart of the idempotency-record fault-injection test
     * above, proving `AUD-004`/`POST-024`: a forced, non-duplicate
     * constraint failure on the `audit_events` insert rolls back the
     * entire transaction — Journal header, Journal Lines, and the
     * idempotency mapping insert together, not just the Audit Event
     * itself.
     */
    public function test_forced_audit_event_failure_rolls_back_the_entire_transaction(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement(
            'ALTER TABLE audit_events ADD CONSTRAINT force_test_audit_failure CHECK (1 = 0)'
        );

        try {
            try {
                $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-audit-atomic'), $this->balancedLines()));
                $this->fail('Expected the forced CHECK constraint to reject the Audit Event insert.');
            } catch (QueryException) {
                // Expected: a non-duplicate constraint violation,
                // propagated unmodified.
            }
        } finally {
            $connection->statement('ALTER TABLE audit_events DROP CONSTRAINT force_test_audit_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-audit-atomic')->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::LINE_TABLE)->where('journal_id', 'journal-audit-atomic')->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->count());
    }

    /**
     * The M6 counterpart proving `AUD-008`: a forced, non-duplicate
     * constraint failure on the `journal_evidence_links` insert rolls
     * back the entire transaction — including the Audit Event that was
     * already written earlier in the same transaction body, never one
     * without the other.
     */
    public function test_forced_evidence_link_failure_rolls_back_the_entire_transaction(): void
    {
        $connection = DB::connection('pgsql');
        $connection->statement(
            'ALTER TABLE journal_evidence_links ADD CONSTRAINT force_test_evidence_link_failure CHECK (1 = 0)'
        );

        $command = new PostingCommand(
            IdempotencyKey::of('key-evidence-atomic'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-evidence-atomic'),
            $this->balancedLines(),
            $this->financialDate(),
            null,
            ['evidence-0001'],
        );

        try {
            try {
                $this->executor->execute($command);
                $this->fail('Expected the forced CHECK constraint to reject the Evidence Linkage insert.');
            } catch (QueryException) {
                // Expected: a non-duplicate constraint violation,
                // propagated unmodified.
            }
        } finally {
            $connection->statement('ALTER TABLE journal_evidence_links DROP CONSTRAINT force_test_evidence_link_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-evidence-atomic')->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->count());
    }

    // --- M6: Audit Event and Evidence Linkage -------------------------------

    public function test_successful_command_produces_an_audit_event_with_the_minimum_captured_fields(): void
    {
        $command = $this->makeCommand($this->tenantA, JournalId::of('journal-audit-content'), $this->balancedLines());

        $result = $this->executor->execute($command);

        /** @var object{tenant_id: string, actor: string, source: string, action: string, journal_id: string} $row */
        $row = DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)
            ->where('journal_id', $result->journal()->id()->toString())
            ->firstOrFail();

        $this->assertSame($this->tenantA->toString(), $row->tenant_id);
        $this->assertSame('actor-0001', $row->actor);
        $this->assertSame('source-0001', $row->source);
        $this->assertSame('JournalPosted', $row->action);
        $this->assertNotNull(DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->where('journal_id', $result->journal()->id()->toString())->value('occurred_at'));
    }

    public function test_successful_command_with_evidence_references_links_them_atomically(): void
    {
        $command = new PostingCommand(
            IdempotencyKey::of('key-evidence-linkage'),
            $this->tenantA,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            JournalId::of('journal-evidence-linkage'),
            $this->balancedLines(),
            $this->financialDate(),
            null,
            ['evidence-0001', 'evidence-0002'],
        );

        $result = $this->executor->execute($command);

        $linkedReferences = DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)
            ->where('journal_id', $result->journal()->id()->toString())
            ->orderBy('evidence_reference')
            ->pluck('evidence_reference')
            ->all();

        $this->assertSame(['evidence-0001', 'evidence-0002'], $linkedReferences);
    }

    public function test_a_journal_with_no_evidence_references_produces_no_linkage_rows(): void
    {
        $result = $this->executor->execute($this->makeCommand($this->tenantA, JournalId::of('journal-no-evidence'), $this->balancedLines()));

        $this->assertSame(0, DB::connection('pgsql')->table(self::EVIDENCE_LINK_TABLE)->where('journal_id', $result->journal()->id()->toString())->count());
    }

    public function test_replay_produces_no_additional_audit_event(): void
    {
        $command = $this->makeCommand($this->tenantA, JournalId::of('journal-audit-replay'), $this->balancedLines(), idempotencyKey: IdempotencyKey::of('key-audit-replay'));

        $this->executor->execute($command);
        $this->executor->execute($command);

        $this->assertSame(1, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->where('journal_id', 'journal-audit-replay')->count());
    }

    // --- Concurrency: two real PostgreSQL connections, genuine race ---------

    /**
     * Two genuinely independent PostgreSQL connections — a forked child
     * process (the winner) and this test's own process (the loser) —
     * call `execute()` for the exact same logical request (same
     * Tenant, same Idempotency Key, same proposed JournalId, same
     * balanced lines) with real overlap: the child opens its own
     * transaction, runs the real Journal-post-and-record write, then
     * holds it open for a short, bounded window before committing.
     *
     * A forked child is used, rather than the two-connections-in-one-
     * process technique every other concurrency test in this suite
     * relies on, because that technique can only ever prove *blocking*
     * (a `lock_timeout` failure) — it cannot make the winner commit
     * while the loser's single blocked statement is still in flight,
     * since both live in the same single-threaded PHP process. Only a
     * genuinely separate process can do that, which is exactly what is
     * required to exercise {@see PostingCommandTransactionalExecutor}'s
     * own race-recovery `catch` for real: this test's own `execute()`
     * call must itself receive the real `DuplicateJournalIdentityException`
     * (M4-T18B) once the child's transaction commits mid-block, and
     * recover from it *within that same call* — never a separate,
     * manual retry (`POST-T103`).
     */
    public function test_two_concurrent_equivalent_first_submissions_are_recovered_within_the_same_execute_call(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('The pcntl and posix extensions are required to genuinely race two equivalent first submissions.');
        }

        $tenantId = $this->tenantA;
        $journalId = JournalId::of('journal-race');
        $key = IdempotencyKey::of('key-race');
        $command = $this->makeCommand($tenantId, $journalId, $this->balancedLines(), idempotencyKey: $key);
        $readyMarker = sys_get_temp_dir().'/posting-race-ready-'.$journalId->toString();
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
                // Child: the winner. An independent connection runs the
                // exact same inner body `execute()` itself would run for
                // a first submission, held open across a bounded sleep
                // before committing.
                DB::purge('pgsql');
                $connection = DB::connection('pgsql');
                $connection->beginTransaction();
                $this->runFirstSubmissionBody($connection, $command);
                // Only now does the parent below know it is safe to
                // attempt its own equivalent submission — without this
                // explicit handshake, which side inserts first is an
                // unbounded race against process-scheduling and
                // connection-setup overhead, not a deterministic test.
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
        // that its write is in place before attempting the same
        // command — never a fixed sleep guessing at relative timing.
        $this->waitForMarker($readyMarker);

        config(['database.connections.pgsql_secondary' => config('database.connections.pgsql')]);
        DB::purge('pgsql_secondary');
        $secondConnection = DB::connection('pgsql_secondary');
        $secondConnection->statement("set lock_timeout = '10000ms'");
        $secondExecutor = $this->buildExecutor($secondConnection);

        try {
            $result = $secondExecutor->execute($command);
        } finally {
            $this->waitForChild($pid);
            DB::purge('pgsql_secondary');
        }

        $this->assertTrue($result->isReplay());
        $this->assertFalse($result->isNewlyPosted());
        $this->assertTrue($result->journal()->id()->equals($journalId));
        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    /**
     * The same genuine forked-process race as above, but the child
     * (winner) posts one logical request while this test's own process
     * (loser) submits a materially different one — same Tenant, same
     * Idempotency Key, different amount, different proposed JournalId.
     * The loser's own Journal insert succeeds without contention (a
     * different JournalId never conflicts with the winner's), but its
     * `record()` call then blocks on the idempotency mapping itself,
     * held open by the child — once the child commits, this process's
     * own blocked insert discovers the real, already-settled duplicate
     * (`DuplicatePostingIdempotencyKeyException`), and, *within that
     * same `execute()` call*, the loser's own rolled-back Journal
     * candidate never survives, re-resolution finds the winner's
     * mapping, and the materially different payload is rejected as a
     * conflicting reuse (`POST-T104`) — never a separate, manual retry.
     */
    public function test_two_concurrent_materially_different_submissions_are_rejected_within_the_same_execute_call(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('The pcntl and posix extensions are required to genuinely race two materially different submissions.');
        }

        $tenantId = $this->tenantA;
        $key = IdempotencyKey::of('key-race-conflict');
        $winningJournalId = JournalId::of('journal-race-winner');
        $winningCommand = $this->makeCommand($tenantId, $winningJournalId, $this->balancedLines(), idempotencyKey: $key);

        $conflictingJournalId = JournalId::of('journal-race-loser');
        $conflictingCommand = $this->makeCommand($tenantId, $conflictingJournalId, [
            $this->debitLine('account-cash', '250.00'),
            $this->creditLine('account-income', '250.00'),
        ], idempotencyKey: $key);
        $readyMarker = sys_get_temp_dir().'/posting-race-ready-'.$winningJournalId->toString();
        @unlink($readyMarker);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            // See the equivalent-race test above for why fd handling and
            // the try/catch/finally shape here are both required.
            fclose(STDOUT);
            fclose(STDERR);
            $devNullOut = fopen('/dev/null', 'w');
            $devNullErr = fopen('/dev/null', 'w');

            try {
                DB::purge('pgsql');
                $connection = DB::connection('pgsql');
                $connection->beginTransaction();
                $this->runFirstSubmissionBody($connection, $winningCommand);
                // Only now does the parent below know it is safe to
                // attempt its own materially different submission — see
                // the equivalent-race test above for why this explicit
                // handshake, rather than a fixed sleep, is required.
                file_put_contents($readyMarker, '1');
                usleep(2_000_000);
                $connection->commit();
            } catch (\Throwable) {
                // Deliberately swallowed — see above.
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $this->waitForMarker($readyMarker);

        config(['database.connections.pgsql_secondary' => config('database.connections.pgsql')]);
        DB::purge('pgsql_secondary');
        $secondConnection = DB::connection('pgsql_secondary');
        $secondConnection->statement("set lock_timeout = '10000ms'");
        $secondExecutor = $this->buildExecutor($secondConnection);

        try {
            $secondExecutor->execute($conflictingCommand);
            $this->fail('Expected the materially different submission to be rejected as a conflicting reuse.');
        } catch (RejectedConflictingIdempotencyReuseException) {
            // Expected — resolved within this same execute() call,
            // after the real database race and rollback.
        } finally {
            $this->waitForChild($pid);
            DB::purge('pgsql_secondary');
        }

        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', $conflictingJournalId->toString())->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    /**
     * Replicates exactly {@see PostingCommandTransactionalExecutor::execute()}'s
     * own first-submission transaction body (Journal post, then record
     * the mapping) using the same real collaborator classes — but
     * without its own `transaction()` wrapper, so a forked child can
     * hold the write open across an artificial delay before committing
     * it itself. This is not a reimplementation of different logic: it
     * is the identical two calls `execute()` itself makes, run under
     * the caller's own already-open transaction instead of the
     * method's internal one, purely to control commit timing for a
     * genuine multi-process race.
     */
    private function runFirstSubmissionBody(ConnectionInterface $connection, PostingCommand $command): void
    {
        $journalRepository = new JournalRepository($connection);
        $journalExecutor = new PostingCommandJournalExecutor(
            new PostingCommandJournalStateResolver($journalRepository),
            new PostingCommandAccountValidator(new AccountRepository($connection)),
            new PostingCommandPeriodLockValidator(new PeriodClosureRepository($connection)),
            new PostingCommandExistingDraftLineValidator,
            new DraftJournalAssembler,
            $journalRepository,
        );
        $idempotencyRepository = new PostingIdempotencyRepository($connection);

        $journal = $journalExecutor->execute($command);
        $idempotencyRepository->record($command->tenantId(), $command->idempotencyKey(), $journal->id());

        (new AuditEventRepository($connection))->record(new AuditEvent(
            $command->tenantId(),
            $command->actor(),
            $command->source(),
            AuditAction::JournalPosted,
            $journal->id(),
        ));

        (new JournalEvidenceLinkRepository($connection))->link(
            $command->tenantId(),
            $journal->id(),
            array_map(
                static fn (string $reference): EvidenceReference => EvidenceReference::of($reference),
                $command->evidenceReferences(),
            ),
        );
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
     * `pcntl_waitpid()` block. The children in this class's own
     * concurrency tests always exit promptly on their own; this only
     * guards against that assumption ever silently becoming false.
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
     * @return list<JournalLine>
     */
    private function balancedLines(): array
    {
        return [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ];
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function makeCommand(
        TenantId $tenantId,
        JournalId $journalId,
        array $lines,
        ?IdempotencyKey $idempotencyKey = null,
    ): PostingCommand {
        return new PostingCommand(
            $idempotencyKey ?? IdempotencyKey::of('key-0001'),
            $tenantId,
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

    private function buildExecutor(ConnectionInterface $connection): PostingCommandTransactionalExecutor
    {
        $journalRepository = new JournalRepository($connection);
        $idempotencyRepository = new PostingIdempotencyRepository($connection);

        $journalExecutor = new PostingCommandJournalExecutor(
            new PostingCommandJournalStateResolver($journalRepository),
            new PostingCommandAccountValidator(new AccountRepository($connection)),
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

        return new PostingCommandTransactionalExecutor(
            $connection,
            $idempotencyResolver,
            $journalExecutor,
            $idempotencyRepository,
            new AuditEventRepository($connection),
            new JournalEvidenceLinkRepository($connection),
        );
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

        // Dependency order matters here: `posting_idempotency_keys`
        // holds a composite foreign key referencing `journals`, so
        // `accounts` and `journals`/`journal_lines` must already exist
        // before it is dropped and recreated.
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
