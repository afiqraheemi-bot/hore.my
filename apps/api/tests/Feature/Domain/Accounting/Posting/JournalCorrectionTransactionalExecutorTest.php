<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Accounting\Posting;

use App\Domain\Accounting\Audit\AuditAction;
use App\Domain\Accounting\Audit\AuditEvent;
use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\CorrectionType;
use App\Domain\Accounting\Journal\Exception\InvalidReplacementTargetException;
use App\Domain\Accounting\Journal\Exception\InvalidReversalTargetException;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\Exception\UnresolvedCorrectionTargetException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\JournalCorrectionCandidateAssembler;
use App\Domain\Accounting\Posting\JournalCorrectionIdempotencyResolver;
use App\Domain\Accounting\Posting\JournalCorrectionLogicalEquivalence;
use App\Domain\Accounting\Posting\JournalCorrectionTransactionalExecutor;
use App\Domain\Accounting\Posting\PostingCommandAccountValidator;
use App\Domain\Accounting\Posting\ReplaceJournalCommand;
use App\Domain\Accounting\Posting\ReverseJournalCommand;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Audit\AuditEventRepository;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountRepository;
use App\Infrastructure\Accounting\Journal\JournalRepository;
use App\Infrastructure\Accounting\Posting\PostingIdempotencyRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Integration-level proof for {@see JournalCorrectionTransactionalExecutor}
 * (M5), exercised against a real PostgreSQL instance — every
 * collaborator it composes (`JournalRepository`,
 * `PostingIdempotencyRepository`, `JournalCorrectionIdempotencyResolver`,
 * `JournalCorrectionCandidateAssembler`) is real, never stubbed,
 * mirroring {@see PostingCommandTransactionalExecutorTest}'s
 * own approach for the M4 executor this class is a deliberate sibling
 * of.
 *
 * Directly evidences the M5 mandate's acceptance criteria: exact
 * Reversal neutrality end-to-end, the original Journal never changing,
 * correction-chain persistence, invalid-relationship rejection,
 * cross-tenant impossibility, idempotent replay, same-key-conflicting-
 * intent rejection, and genuine multi-process concurrency producing no
 * duplicate economic correction.
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection (e.g. `docker compose up -d postgres` has not been run —
 * see `docker-compose.yml`), every test in this class is skipped with
 * an explicit reason.
 */
final class JournalCorrectionTransactionalExecutorTest extends TestCase
{
    private const IDEMPOTENCY_TABLE = 'posting_idempotency_keys';

    private const JOURNAL_TABLE = 'journals';

    private const LINE_TABLE = 'journal_lines';

    private const ACCOUNT_TABLE = 'accounts';

    private const IDEMPOTENCY_MIGRATION_PATH = 'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php';

    private const JOURNAL_MIGRATION_PATH = 'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php';

    private const CORRECTION_MIGRATION_PATH = 'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php';

    private const ACCOUNTS_MIGRATION_PATH = 'database/migrations/2026_09_04_030000_create_accounts_table.php';

    private const AUDIT_EVENT_TABLE = 'audit_events';

    private const AUDIT_EVENT_MIGRATION_PATH = 'database/migrations/2026_09_06_200000_create_audit_events_table.php';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private JournalCorrectionTransactionalExecutor $executor;

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

        DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->delete();
        DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->delete();
        DB::connection('pgsql')->table(self::LINE_TABLE)->delete();
        DB::connection('pgsql')->table(self::JOURNAL_TABLE)->delete();
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->delete();

        $connection = DB::connection('pgsql');
        $this->journalRepository = new JournalRepository($connection);
        $this->executor = $this->buildExecutor($connection);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-cash');
        $this->insertAccount($this->tenantA, 'account-income');
        $this->insertAccount($this->tenantA, 'account-inactive', active: false);
        $this->insertAccount($this->tenantB, 'account-cash-b');
        $this->insertAccount($this->tenantB, 'account-income-b');
    }

    // --- Reversal: first submission, neutrality, traceability ------------

    public function test_reversal_fresh_key_produces_a_newly_posted_result(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $result = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()));

        $this->assertTrue($result->isNewlyPosted());
        $this->assertSame(JournalState::Posted, $result->journal()->state());
    }

    public function test_reversal_neutralizes_every_line_and_carries_the_correction_chain(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $result = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()));
        $reversal = $result->journal();

        $this->assertSame(CorrectionType::Reversal, $reversal->correctionType());
        $this->assertTrue($reversal->correctedJournalId()?->equals($original->id()));

        $originalLines = $original->lines();
        $reversalLines = $reversal->lines();
        $this->assertCount(count($originalLines), $reversalLines);

        foreach ($originalLines as $index => $originalLine) {
            $reversalLine = $reversalLines[$index];
            $this->assertTrue($originalLine->accountId()->equals($reversalLine->accountId()));
            $this->assertTrue($originalLine->money()->equals($reversalLine->money()));
            $this->assertNotSame($originalLine->direction(), $reversalLine->direction());
        }
    }

    public function test_reversal_leaves_the_original_journal_completely_unchanged(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()));

        $reloadedOriginal = $this->journalRepository->findById($this->tenantA, $original->id());
        $this->assertNotNull($reloadedOriginal);
        $this->assertSame(JournalState::Posted, $reloadedOriginal->state());
        $this->assertNull($reloadedOriginal->correctionType());
        $this->assertNull($reloadedOriginal->correctedJournalId());
        $this->assertTrue($original->equals($reloadedOriginal));
    }

    public function test_reversal_replay_returns_the_original_result_without_a_new_row(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $key = IdempotencyKey::of('key-reversal-replay');
        $command = $this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id(), $key);

        $first = $this->executor->executeReversal($command);
        $second = $this->executor->executeReversal($command);

        $this->assertTrue($first->isNewlyPosted());
        $this->assertTrue($second->isReplay());
        $this->assertTrue($first->journal()->id()->equals($second->journal()->id()));
        $this->assertSame(2, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    public function test_reversal_same_key_against_a_different_original_is_a_conflicting_reuse(): void
    {
        $originalOne = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original-one'), $this->balancedLines());
        $originalTwo = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original-two'), $this->balancedLines());
        $key = IdempotencyKey::of('key-reversal-conflict');

        $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal-one'), $originalOne->id(), $key));

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);

        $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal-two'), $originalTwo->id(), $key));
    }

    public function test_reversing_a_draft_journal_is_rejected(): void
    {
        $draft = $this->draftJournal($this->tenantA, JournalId::of('journal-draft'), $this->balancedLines());

        $this->expectException(InvalidReversalTargetException::class);

        $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $draft->id()));
    }

    public function test_reversing_a_reversal_is_rejected(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversal = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()))->journal();

        $this->expectException(InvalidReversalTargetException::class);

        $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-double-reversal'), $reversal->id()));
    }

    public function test_reversing_a_nonexistent_journal_is_rejected(): void
    {
        $this->expectException(UnresolvedCorrectionTargetException::class);

        $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), JournalId::of('journal-nonexistent')));
    }

    public function test_reversing_another_tenants_journal_is_impossible(): void
    {
        $originalForTenantB = $this->postOrdinaryJournal($this->tenantB, JournalId::of('journal-owned-by-b'), [
            $this->debitLine('account-cash-b', '50.00'),
            $this->creditLine('account-income-b', '50.00'),
        ]);

        $this->expectException(UnresolvedCorrectionTargetException::class);

        $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $originalForTenantB->id()));
    }

    public function test_reversal_against_an_inactive_account_is_rejected(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), [
            $this->debitLine('account-inactive', '50.00'),
            $this->creditLine('account-income', '50.00'),
        ]);

        $this->expectException(RejectedAccountReferenceException::class);

        $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()));
    }

    // --- Replacement: first submission, traceability ----------------------

    public function test_replacement_fresh_key_produces_a_newly_posted_result_with_caller_lines(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversal = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()))->journal();

        $replacementLines = [
            $this->debitLine('account-cash', '120.00'),
            $this->creditLine('account-income', '120.00'),
        ];
        $result = $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement'), $reversal->id(), $replacementLines));
        $replacement = $result->journal();

        $this->assertTrue($result->isNewlyPosted());
        $this->assertSame(CorrectionType::Replacement, $replacement->correctionType());
        $this->assertTrue($replacement->correctedJournalId()?->equals($reversal->id()));
        $this->assertTrue($replacement->lines()[0]->money()->equals($replacementLines[0]->money()));
    }

    public function test_replacement_to_reversal_to_original_chain_is_traceable(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversal = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()))->journal();
        $replacement = $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement'), $reversal->id(), $this->balancedLines()))->journal();

        $reloadedReplacement = $this->journalRepository->findById($this->tenantA, $replacement->id());
        $this->assertNotNull($reloadedReplacement);
        $this->assertTrue($reloadedReplacement->correctedJournalId()?->equals($reversal->id()));

        $reloadedReversal = $this->journalRepository->findById($this->tenantA, $reversal->id());
        $this->assertNotNull($reloadedReversal);
        $this->assertTrue($reloadedReversal->correctedJournalId()?->equals($original->id()));
    }

    public function test_replacement_replay_returns_the_original_result_without_a_new_row(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversal = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()))->journal();
        $key = IdempotencyKey::of('key-replacement-replay');
        $command = $this->replaceCommand($this->tenantA, JournalId::of('journal-replacement'), $reversal->id(), $this->balancedLines(), $key);

        $first = $this->executor->executeReplacement($command);
        $second = $this->executor->executeReplacement($command);

        $this->assertTrue($first->isNewlyPosted());
        $this->assertTrue($second->isReplay());
        $this->assertSame(3, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
    }

    public function test_replacement_same_key_with_different_lines_is_a_conflicting_reuse(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversal = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()))->journal();
        $key = IdempotencyKey::of('key-replacement-conflict');

        $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement-one'), $reversal->id(), $this->balancedLines(), $key));

        $this->expectException(RejectedConflictingIdempotencyReuseException::class);

        $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement-two'), $reversal->id(), [
            $this->debitLine('account-cash', '999.00'),
            $this->creditLine('account-income', '999.00'),
        ], $key));
    }

    public function test_replacement_referencing_an_ordinary_journal_directly_is_rejected(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $this->expectException(InvalidReplacementTargetException::class);

        $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement'), $original->id(), $this->balancedLines()));
    }

    public function test_replacement_referencing_a_draft_reversal_is_rejected(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $draftReversal = $original->reverse(JournalId::of('journal-draft-reversal'));
        $this->journalRepository->save($draftReversal);

        $this->expectException(InvalidReplacementTargetException::class);

        $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement'), $draftReversal->id(), $this->balancedLines()));
    }

    public function test_replacement_referencing_a_nonexistent_reversal_is_rejected(): void
    {
        $this->expectException(UnresolvedCorrectionTargetException::class);

        $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement'), JournalId::of('journal-nonexistent'), $this->balancedLines()));
    }

    public function test_replacement_lines_against_an_inactive_account_is_rejected(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversal = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()))->journal();

        $this->expectException(RejectedAccountReferenceException::class);

        $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement'), $reversal->id(), [
            $this->debitLine('account-inactive', '50.00'),
            $this->creditLine('account-income', '50.00'),
        ]));
    }

    // --- Atomicity ---------------------------------------------------------

    public function test_a_rejected_reversal_leaves_no_partial_persistence_effect(): void
    {
        $draft = $this->draftJournal($this->tenantA, JournalId::of('journal-draft'), $this->balancedLines());

        try {
            $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $draft->id()));
            $this->fail('Expected the reversal of a Draft Journal to be rejected.');
        } catch (InvalidReversalTargetException) {
            // Expected.
        }

        $this->assertSame(1, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    // --- M6: Audit Event ------------------------------------------------

    /**
     * Mirrors {@see PostingCommandTransactionalExecutorTest::test_forced_audit_event_failure_rolls_back_the_entire_transaction()}
     * for the M5 correction executor: a forced, non-duplicate constraint
     * failure on the `audit_events` insert rolls back the entire
     * transaction — the Reversal Journal and the idempotency mapping
     * together, never just the Audit Event (`AUD-004`).
     */
    public function test_forced_audit_event_failure_rolls_back_the_entire_reversal_transaction(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $connection = DB::connection('pgsql');
        $connection->statement(
            'ALTER TABLE audit_events ADD CONSTRAINT force_test_correction_audit_failure CHECK (1 = 0)'
        );

        try {
            try {
                $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal-atomic'), $original->id()));
                $this->fail('Expected the forced CHECK constraint to reject the Audit Event insert.');
            } catch (QueryException) {
                // Expected: a non-duplicate constraint violation,
                // propagated unmodified.
            }
        } finally {
            $connection->statement('ALTER TABLE audit_events DROP CONSTRAINT force_test_correction_audit_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->where('journal_id', 'journal-reversal-atomic')->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
        $this->assertSame(0, DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->count());
    }

    public function test_reversal_produces_an_audit_event_with_the_minimum_captured_fields(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $result = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal-audit'), $original->id()));

        /** @var object{tenant_id: string, actor: string, source: string, action: string} $row */
        $row = DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)
            ->where('journal_id', $result->journal()->id()->toString())
            ->firstOrFail();

        $this->assertSame($this->tenantA->toString(), $row->tenant_id);
        $this->assertSame('actor-0001', $row->actor);
        $this->assertSame('source-0001', $row->source);
        $this->assertSame('JournalReversed', $row->action);
    }

    public function test_replacement_produces_an_audit_event_with_action_journal_replaced(): void
    {
        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversal = $this->executor->executeReversal($this->reverseCommand($this->tenantA, JournalId::of('journal-reversal'), $original->id()))->journal();

        $result = $this->executor->executeReplacement($this->replaceCommand($this->tenantA, JournalId::of('journal-replacement-audit'), $reversal->id(), $this->balancedLines()));

        $this->assertSame(
            'JournalReplaced',
            DB::connection('pgsql')->table(self::AUDIT_EVENT_TABLE)->where('journal_id', $result->journal()->id()->toString())->value('action'),
        );
    }

    // --- Concurrency: genuine multi-process races --------------------------

    /**
     * A genuine forked-process race, mirroring
     * {@see PostingCommandTransactionalExecutorTest}'s
     * own equivalent-race test exactly, but for two equivalent Reversal
     * submissions under the same (Tenant, Idempotency Key): the child
     * wins the real `journals`/`posting_idempotency_keys` race, the
     * parent's own `executeReversal()` call observes the real
     * `DuplicateJournalIdentityException`/`DuplicatePostingIdempotencyKeyException`
     * and recovers within that same call, resolving to a replay of the
     * winner's Journal — never a second, duplicate Reversal.
     */
    public function test_two_concurrent_equivalent_reversals_are_recovered_within_the_same_execute_call(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('The pcntl and posix extensions are required to genuinely race two equivalent Reversal submissions.');
        }

        $original = $this->postOrdinaryJournal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversalJournalId = JournalId::of('journal-reversal-race');
        $key = IdempotencyKey::of('key-reversal-race');
        $command = $this->reverseCommand($this->tenantA, $reversalJournalId, $original->id(), $key);
        $readyMarker = sys_get_temp_dir().'/correction-race-ready-'.$reversalJournalId->toString();
        @unlink($readyMarker);

        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            fclose(STDOUT);
            fclose(STDERR);
            $devNullOut = fopen('/dev/null', 'w');
            $devNullErr = fopen('/dev/null', 'w');

            try {
                DB::purge('pgsql');
                $connection = DB::connection('pgsql');
                $connection->beginTransaction();
                $this->runFirstSubmissionBody($connection, $command);
                file_put_contents($readyMarker, '1');
                usleep(2_000_000);
                $connection->commit();
            } catch (\Throwable) {
                // Deliberately swallowed — a forked copy of the test
                // process must never let PHPUnit's own exception
                // handling run twice; see the M4 executor test this
                // mirrors for the full reasoning.
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
            $result = $secondExecutor->executeReversal($command);
        } finally {
            $this->waitForChild($pid);
            DB::purge('pgsql_secondary');
        }

        $this->assertTrue($result->isReplay());
        $this->assertTrue($result->journal()->id()->equals($reversalJournalId));
        $this->assertSame(2, DB::connection('pgsql')->table(self::JOURNAL_TABLE)->count());
        $this->assertSame(1, DB::connection('pgsql')->table(self::IDEMPOTENCY_TABLE)->count());
    }

    /**
     * Replicates {@see JournalCorrectionTransactionalExecutor::executeReversal()}'s
     * own first-submission transaction body (candidate assembly, post,
     * record) under the caller's own already-open transaction, exactly
     * mirroring {@see PostingCommandTransactionalExecutorTest::runFirstSubmissionBody()}'s
     * technique for M4 — purely to control commit timing for a genuine
     * multi-process race.
     */
    private function runFirstSubmissionBody(ConnectionInterface $connection, ReverseJournalCommand $command): void
    {
        $journalRepository = new JournalRepository($connection);
        $assembler = new JournalCorrectionCandidateAssembler(
            $journalRepository,
            new PostingCommandAccountValidator(new AccountRepository($connection)),
        );
        $idempotencyRepository = new PostingIdempotencyRepository($connection);

        $candidate = $assembler->assembleReversal($command);
        $posted = $candidate->post();
        $journalRepository->save($posted);
        $idempotencyRepository->record($command->tenantId(), $command->idempotencyKey(), $posted->id());

        (new AuditEventRepository($connection))->record(new AuditEvent(
            $command->tenantId(),
            $command->actor(),
            $command->source(),
            AuditAction::JournalReversed,
            $posted->id(),
        ));
    }

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

    // --- Fixtures and helpers ------------------------------------------

    private function postOrdinaryJournal(TenantId $tenantId, JournalId $journalId, array $lines): Journal
    {
        $journal = Journal::create($tenantId, $journalId, $lines)->post();
        $this->journalRepository->save($journal);

        return $journal;
    }

    private function draftJournal(TenantId $tenantId, JournalId $journalId, array $lines): Journal
    {
        $journal = Journal::create($tenantId, $journalId, $lines);
        $this->journalRepository->save($journal);

        return $journal;
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

    private function reverseCommand(TenantId $tenantId, JournalId $newJournalId, JournalId $originalJournalId, ?IdempotencyKey $idempotencyKey = null): ReverseJournalCommand
    {
        return new ReverseJournalCommand(
            $idempotencyKey ?? IdempotencyKey::of('key-'.$newJournalId->toString()),
            $tenantId,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            $newJournalId,
            $originalJournalId,
        );
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function replaceCommand(TenantId $tenantId, JournalId $newJournalId, JournalId $reversalJournalId, array $lines, ?IdempotencyKey $idempotencyKey = null): ReplaceJournalCommand
    {
        return new ReplaceJournalCommand(
            $idempotencyKey ?? IdempotencyKey::of('key-'.$newJournalId->toString()),
            $tenantId,
            ActorReference::of('actor-0001'),
            SourceReference::of('source-0001'),
            $newJournalId,
            $reversalJournalId,
            $lines,
        );
    }

    private function buildExecutor(ConnectionInterface $connection): JournalCorrectionTransactionalExecutor
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

    private function insertAccount(TenantId $tenantId, string $accountId, bool $active = true): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId->toString().$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => 'Asset',
            'account_origin' => 'UserCreated',
            'active' => $active,
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
