<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Workspace;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\Exception\InvalidTaskStateTransitionException;
use App\Domain\Workspace\Exception\TaskAlreadyTransitionedException;
use App\Domain\Workspace\Exception\TaskNotFoundException;
use App\Domain\Workspace\Exception\TaskSubmissionConflictException;
use App\Domain\Workspace\Task;
use App\Domain\Workspace\TaskService;
use App\Domain\Workspace\TaskState;
use App\Domain\Workspace\TaskTransition;
use App\Infrastructure\Workspace\ProposalRepository;
use App\Infrastructure\Workspace\TaskRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CleansSharedAccountingTables;
use Tests\Feature\Domain\Payments\AllocationServiceIntegrationTest;
use Tests\TestCase;

/**
 * Integration-level proof for {@see TaskService} (ADR-0009, WTS-001) —
 * exercised against a real PostgreSQL instance and the real
 * `ExpenseRecordingService`/`IncomeRecordingService`/
 * `TransferRecordingService`/`OwnerEquityTransactionRecordingService`
 * resolved through the application container exactly as
 * `TaskController` resolves them in production — no stubbing of
 * Accounting Core, per ADR-0009's own core claim that this module
 * introduces no new posting path.
 *
 * Directly evidences: TSK-001 (a transition audit record for every
 * transition), TSK-002 (invalid transitions rejected), TSK-003 (an
 * approved Proposal posts through the same Command contract manual
 * entry uses), TSK-004 (a genuine two-process concurrency proof that
 * two concurrent `approve()` calls against the same Task can never
 * both succeed), and TSK-007 (cross-tenant isolation).
 *
 * If no real PostgreSQL instance is reachable via the `pgsql`
 * connection, every test in this class is skipped with an explicit
 * reason.
 */
final class TaskServiceIntegrationTest extends TestCase
{
    use CleansSharedAccountingTables;

    private const ACCOUNT_TABLE = 'accounts';

    private static ?string $skipReason = null;

    private static bool $migrated = false;

    private TaskService $taskService;

    private TaskRepository $taskRepository;

    private ProposalRepository $proposalRepository;

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

        self::cleanSharedAccountingTables();

        $this->taskService = $this->app->make(TaskService::class);
        $this->taskRepository = $this->app->make(TaskRepository::class);
        $this->proposalRepository = $this->app->make(ProposalRepository::class);

        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');

        $this->insertAccount($this->tenantA, 'account-office-supplies', 'Expense');
        $this->insertAccount($this->tenantA, 'account-cash', 'Asset');
        $this->insertAccount($this->tenantB, 'account-office-supplies-b', 'Expense');
        $this->insertAccount($this->tenantB, 'account-cash-b', 'Asset');
    }

    public function test_submit_lands_the_task_in_needs_review_with_a_human_proposal(): void
    {
        $task = $this->submitExpense();

        $this->assertSame(TaskState::NeedsReview, $task->state());
        $this->assertSame(1, DB::connection('pgsql')->table('proposals')->where('task_id', $task->id()->toString())->count());
    }

    public function test_submit_is_idempotent_under_the_same_idempotency_key(): void
    {
        $actor = ActorReference::of('user-0001');
        $first = $this->taskService->submit(
            $this->tenantA, $actor, IdempotencyKey::of('idem-submit-0001'),
            CommandType::Expense, Money::fromDecimalString('50.00', $this->myr), new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-office-supplies'), AccountId::of('account-cash'), 'Office supplies', null,
        );
        $second = $this->taskService->submit(
            $this->tenantA, $actor, IdempotencyKey::of('idem-submit-0001'),
            CommandType::Expense, Money::fromDecimalString('50.00', $this->myr), new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-office-supplies'), AccountId::of('account-cash'), 'Office supplies', null,
        );

        $this->assertTrue($first->id()->equals($second->id()));
        $this->assertSame(1, DB::connection('pgsql')->table('tasks')->count());
    }

    public function test_approve_completes_the_task_and_posts_a_balanced_journal(): void
    {
        $task = $this->submitExpense();

        $completed = $this->taskService->approve($this->tenantA, $task->id(), ActorReference::of('user-0001'));

        $this->assertSame(TaskState::Completed, $completed->state());
        $this->assertNotNull($completed->resultJournalId());

        $journal = DB::connection('pgsql')->table('journals')
            ->where('tenant_id', $this->tenantA->toString())
            ->where('journal_id', $completed->resultJournalId()?->toString())
            ->first();
        $this->assertNotNull($journal);
        $this->assertSame('Posted', $journal->state);
    }

    public function test_approve_is_rejected_once_already_rejected(): void
    {
        $task = $this->submitExpense();
        $this->taskService->reject($this->tenantA, $task->id(), ActorReference::of('user-0001'), 'Wrong account.');

        $this->expectException(InvalidTaskStateTransitionException::class);
        $this->taskService->approve($this->tenantA, $task->id(), ActorReference::of('user-0001'));
    }

    public function test_every_transition_in_the_happy_path_is_recorded_with_actor_and_reason(): void
    {
        $task = $this->submitExpense();
        $this->taskService->approve($this->tenantA, $task->id(), ActorReference::of('user-approver'));

        $transitions = $this->taskRepository->findTransitionsFor($this->tenantA, $task->id());
        $sequence = array_map(static fn ($t): string => ($t->fromState()?->name ?? 'null').'->'.$t->toState()->name, $transitions);

        $this->assertSame([
            'null->Received',
            'Received->Processing',
            'Processing->NeedsReview',
            'NeedsReview->Approved',
            'Approved->Executing',
            'Executing->Completed',
        ], $sequence);

        foreach ($transitions as $transition) {
            $this->assertNotSame('', $transition->actor()->toString());
        }
    }

    public function test_reject_requires_a_task_in_needs_review(): void
    {
        $task = $this->submitExpense();
        $this->taskService->reject($this->tenantA, $task->id(), ActorReference::of('user-0001'), 'Duplicate.');

        $this->expectException(InvalidTaskStateTransitionException::class);
        $this->taskService->reject($this->tenantA, $task->id(), ActorReference::of('user-0001'), 'Again.');
    }

    public function test_cancel_is_rejected_after_completion(): void
    {
        $task = $this->submitExpense();
        $this->taskService->approve($this->tenantA, $task->id(), ActorReference::of('user-0001'));

        $this->expectException(InvalidTaskStateTransitionException::class);
        $this->taskService->cancel($this->tenantA, $task->id(), ActorReference::of('user-0001'), 'Changed my mind.');
    }

    public function test_a_task_created_under_one_tenant_is_invisible_to_another(): void
    {
        $task = $this->submitExpense();

        $this->expectException(TaskNotFoundException::class);
        $this->taskService->approve($this->tenantB, $task->id(), ActorReference::of('user-b'));
    }

    /**
     * The TSK-004 concurrency guard, proven with two genuine OS
     * processes racing the same `approve()` call — a single-threaded
     * PHPUnit process cannot reproduce the actual race window between
     * "read current state" and "compare-and-swap write," mirroring
     * {@see AllocationServiceIntegrationTest::test_two_concurrent_deallocate_attempts_never_corrupt_the_audit_trail()}'s
     * own reasoning exactly.
     *
     * **The loser observes one of two distinct exceptions, both
     * equally safe.** `approve()` walks `NeedsReview -> Approved ->
     * Executing` as two separate `applyTransition()` calls, not one
     * multi-step atomic swap. If the loser's own `getById()` read lands
     * before the winner's first CAS, it loses that CAS itself and sees
     * {@see TaskAlreadyTransitionedException}. If the loser's read
     * instead lands in the (typically sub-millisecond) window *between*
     * the winner's two CAS calls, it observes `Approved` directly and
     * its own in-memory `Task::approve()` rejects the transition before
     * ever reaching a second CAS, surfacing
     * {@see InvalidTaskStateTransitionException} instead. Both are
     * correct, safe outcomes — what TSK-004 actually guarantees is that
     * the loser can never also reach `Completed`, not that it always
     * fails via the same code path.
     */
    public function test_two_concurrent_approve_attempts_never_both_succeed(): void
    {
        $task = $this->submitExpense();

        $tmp = sys_get_temp_dir();
        $readyA = tempnam($tmp, 'ready_a_');
        $readyB = tempnam($tmp, 'ready_b_');
        $goFile = tempnam($tmp, 'go_');
        $resultA = tempnam($tmp, 'result_a_');
        $resultB = tempnam($tmp, 'result_b_');
        unlink($readyA);
        unlink($readyB);
        unlink($goFile);

        $workerScript = base_path('tests/bin/concurrent_task_approve_worker.php');

        $processA = proc_open(
            ['php', $workerScript, $this->tenantA->toString(), $task->id()->toString(), 'user-concurrent-a', $readyA, $goFile, $resultA],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $processB = proc_open(
            ['php', $workerScript, $this->tenantA->toString(), $task->id()->toString(), 'user-concurrent-b', $readyB, $goFile, $resultB],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB,
        );

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        $deadline = microtime(true) + 5.0;
        while (! (file_exists($readyA) && file_exists($readyB))) {
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for both worker processes to signal ready.');
            }
            usleep(2000);
        }

        touch($goFile);

        foreach ([$processA, $processB] as $process) {
            proc_close($process);
        }

        $deadline = microtime(true) + 5.0;
        while (! (file_exists($resultA) && file_exists($resultB))) {
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for both worker results.');
            }
            usleep(2000);
        }

        $resultAData = json_decode((string) file_get_contents($resultA), true);
        $resultBData = json_decode((string) file_get_contents($resultB), true);

        foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $file) {
            @unlink($file);
        }

        $outcomes = ['A' => $resultAData, 'B' => $resultBData];
        $succeeded = array_keys(array_filter($outcomes, static fn (array $r): bool => ($r['state'] ?? null) === 'Completed'));
        $rejected = array_keys(array_filter($outcomes, static fn (array $r): bool => in_array(
            $r['exception'] ?? null,
            [TaskAlreadyTransitionedException::class, InvalidTaskStateTransitionException::class],
            true,
        )));

        $this->assertCount(1, $succeeded, 'Exactly one of the two concurrent approve attempts must complete. Got: '.json_encode($outcomes));
        $this->assertCount(1, $rejected, 'The other concurrent attempt must be safely rejected (either TaskAlreadyTransitionedException or InvalidTaskStateTransitionException — see this test\'s own docblock). Got: '.json_encode($outcomes));

        $final = $this->taskRepository->getById($this->tenantA, $task->id());
        $this->assertSame(TaskState::Completed, $final->state());
    }

    /**
     * 2026-09-08 reliability closure (post-implementation QA): proves
     * `submit()`'s whole Task+Proposal creation sequence is one atomic
     * transaction — a forced, non-duplicate constraint failure on the
     * `proposals` insert (the last write in the sequence) must roll
     * back the `tasks` row and its `task_transitions` rows too, not
     * leave a stranded Task with no Proposal. Mirrors
     * {@see PostingCommandTransactionalExecutor}'s
     * own established fault-injection technique exactly.
     */
    public function test_a_forced_proposal_insert_failure_rolls_back_the_entire_submit_transaction(): void
    {
        DB::connection('pgsql')->statement('ALTER TABLE proposals ADD CONSTRAINT force_test_proposal_failure CHECK (1 = 0)');

        try {
            try {
                $this->submitExpense();
                $this->fail('Expected the forced CHECK constraint to reject the Proposal insert.');
            } catch (QueryException) {
                // Expected: a non-duplicate constraint violation, propagated unmodified.
            }
        } finally {
            DB::connection('pgsql')->statement('ALTER TABLE proposals DROP CONSTRAINT force_test_proposal_failure');
        }

        $this->assertSame(0, DB::connection('pgsql')->table('tasks')->where('tenant_id', $this->tenantA->toString())->count());
        $this->assertSame(0, DB::connection('pgsql')->table('task_transitions')->where('tenant_id', $this->tenantA->toString())->count());
    }

    /**
     * Proves {@see TaskService}'s
     * `applyTransition()` state-update-plus-audit-record atomicity: a
     * forced, non-duplicate constraint failure on the
     * `task_transitions` insert must roll back the compare-and-swap
     * `UPDATE` on `tasks.state` too — TSK-001 requires that `state`
     * can never disagree with the Task's own transition history.
     */
    public function test_a_forced_transition_insert_failure_rolls_back_the_state_update_too(): void
    {
        $task = $this->submitExpense();

        // NOT VALID: task_transitions already holds the 3 rows submitExpense()
        // just wrote — a validating ADD CONSTRAINT would immediately fail
        // against that pre-existing data before this test ever reaches its
        // own forced-failure attempt. NOT VALID skips that historical check
        // and enforces the constraint only against rows written from here on.
        DB::connection('pgsql')->statement('ALTER TABLE task_transitions ADD CONSTRAINT force_test_transition_failure CHECK (1 = 0) NOT VALID');

        try {
            try {
                $this->taskService->reject($this->tenantA, $task->id(), ActorReference::of('user-0001'), 'Wrong account.');
                $this->fail('Expected the forced CHECK constraint to reject the transition insert.');
            } catch (QueryException) {
                // Expected.
            }
        } finally {
            DB::connection('pgsql')->statement('ALTER TABLE task_transitions DROP CONSTRAINT force_test_transition_failure');
        }

        $reloaded = $this->taskRepository->getById($this->tenantA, $task->id());
        $this->assertSame(TaskState::NeedsReview, $reloaded->state(), 'The state UPDATE must have rolled back alongside the failed audit insert.');
    }

    public function test_submit_with_a_conflicting_payload_under_the_same_idempotency_key_is_rejected(): void
    {
        $key = IdempotencyKey::of('idem-conflict-0001');
        $actor = ActorReference::of('user-0001');

        $this->taskService->submit(
            $this->tenantA, $actor, $key, CommandType::Expense,
            Money::fromDecimalString('50.00', $this->myr), new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-office-supplies'), AccountId::of('account-cash'), 'Office supplies', null,
        );

        $this->expectException(TaskSubmissionConflictException::class);

        $this->taskService->submit(
            $this->tenantA, $actor, $key, CommandType::Expense,
            Money::fromDecimalString('999.00', $this->myr), new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-office-supplies'), AccountId::of('account-cash'), 'Office supplies', null,
        );
    }

    public function test_submit_with_an_unchanged_payload_under_the_same_idempotency_key_replays(): void
    {
        $key = IdempotencyKey::of('idem-replay-0001');
        $actor = ActorReference::of('user-0001');
        $args = [
            $this->tenantA, $actor, $key, CommandType::Expense,
            Money::fromDecimalString('50.00', $this->myr), new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-office-supplies'), AccountId::of('account-cash'), 'Office supplies', null,
        ];

        $first = $this->taskService->submit(...$args);
        $second = $this->taskService->submit(...$args);

        $this->assertTrue($first->id()->equals($second->id()));
        $this->assertSame(1, DB::connection('pgsql')->table('tasks')->count());
    }

    /**
     * Crash recovery: a Task stranded in `Executing` (simulated by
     * writing that state directly, exactly as a real crash between the
     * `Executing` transition and Command submission would leave it —
     * `resume()` has no way to distinguish the two) can still reach
     * `Completed` via {@see TaskService::resume()}.
     */
    public function test_resume_completes_a_task_stranded_in_executing(): void
    {
        $task = $this->submitExpense();
        DB::connection('pgsql')->table('tasks')
            ->where('tenant_id', $this->tenantA->toString())->where('task_id', $task->id()->toString())
            ->update(['state' => 'Executing']);

        $resumed = $this->taskService->resume($this->tenantA, $task->id(), ActorReference::of('user-recovery'));

        $this->assertSame(TaskState::Completed, $resumed->state());
        $this->assertNotNull($resumed->resultJournalId());
    }

    public function test_resume_is_rejected_from_a_non_executing_state(): void
    {
        $task = $this->submitExpense();

        $this->expectException(InvalidTaskStateTransitionException::class);
        $this->taskService->resume($this->tenantA, $task->id(), ActorReference::of('user-recovery'));
    }

    /**
     * The TSK-004 concurrency guard extended to {@see resume()}: two
     * concurrent recovery attempts against the same stranded
     * `Executing` Task must never both succeed. Mirrors
     * {@see test_two_concurrent_approve_attempts_never_both_succeed()}
     * exactly.
     */
    public function test_two_concurrent_resume_attempts_never_both_succeed(): void
    {
        $task = $this->submitExpense();
        DB::connection('pgsql')->table('tasks')
            ->where('tenant_id', $this->tenantA->toString())->where('task_id', $task->id()->toString())
            ->update(['state' => 'Executing']);

        $tmp = sys_get_temp_dir();
        $readyA = tempnam($tmp, 'ready_a_');
        $readyB = tempnam($tmp, 'ready_b_');
        $goFile = tempnam($tmp, 'go_');
        $resultA = tempnam($tmp, 'result_a_');
        $resultB = tempnam($tmp, 'result_b_');
        unlink($readyA);
        unlink($readyB);
        unlink($goFile);

        $workerScript = base_path('tests/bin/concurrent_task_resume_worker.php');

        $processA = proc_open(
            ['php', $workerScript, $this->tenantA->toString(), $task->id()->toString(), 'user-concurrent-a', $readyA, $goFile, $resultA],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA,
        );
        $processB = proc_open(
            ['php', $workerScript, $this->tenantA->toString(), $task->id()->toString(), 'user-concurrent-b', $readyB, $goFile, $resultB],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB,
        );

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        $deadline = microtime(true) + 5.0;
        while (! (file_exists($readyA) && file_exists($readyB))) {
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for both worker processes to signal ready.');
            }
            usleep(2000);
        }

        touch($goFile);

        foreach ([$processA, $processB] as $process) {
            proc_close($process);
        }

        $deadline = microtime(true) + 5.0;
        while (! (file_exists($resultA) && file_exists($resultB))) {
            if (microtime(true) > $deadline) {
                $this->fail('Timed out waiting for both worker results.');
            }
            usleep(2000);
        }

        $resultAData = json_decode((string) file_get_contents($resultA), true);
        $resultBData = json_decode((string) file_get_contents($resultB), true);

        foreach ([$readyA, $readyB, $goFile, $resultA, $resultB] as $file) {
            @unlink($file);
        }

        $outcomes = ['A' => $resultAData, 'B' => $resultBData];
        $succeeded = array_keys(array_filter($outcomes, static fn (array $r): bool => ($r['state'] ?? null) === 'Completed'));
        $rejected = array_keys(array_filter($outcomes, static fn (array $r): bool => in_array(
            $r['exception'] ?? null,
            [TaskAlreadyTransitionedException::class, InvalidTaskStateTransitionException::class],
            true,
        )));

        $this->assertCount(1, $succeeded, 'Exactly one of the two concurrent resume attempts must complete. Got: '.json_encode($outcomes));
        $this->assertCount(1, $rejected, 'The other concurrent attempt must be safely rejected. Got: '.json_encode($outcomes));
    }

    /**
     * Proves the repository persists the domain-supplied
     * {@see TaskTransition} id verbatim rather
     * than substituting a freshly generated one — a real bug this
     * session's own reliability closure found and fixed. Constructs
     * the record directly (bypassing {@see TaskService}, which always
     * generates its own id) so the assertion cannot trivially pass by
     * reading back whatever id happened to be written.
     */
    public function test_the_domain_supplied_transition_id_is_persisted_verbatim(): void
    {
        $task = $this->submitExpense();
        $knownId = 'known-transition-id-0001';

        $this->taskRepository->recordTransition(new TaskTransition(
            $knownId, $this->tenantA, $task->id(), ActorReference::of('user-0001'),
            TaskState::NeedsReview, TaskState::NeedsReview, 'test marker', null, new \DateTimeImmutable,
        ));

        $row = DB::connection('pgsql')->table('task_transitions')->where('transition_id', $knownId)->first();
        $this->assertNotNull($row, 'The repository must persist the exact id the domain TaskTransition was constructed with, not a freshly generated one.');
    }

    private function submitExpense(): Task
    {
        return $this->taskService->submit(
            $this->tenantA,
            ActorReference::of('user-0001'),
            IdempotencyKey::of('idem-'.uniqid('', true)),
            CommandType::Expense,
            Money::fromDecimalString('50.00', $this->myr),
            new \DateTimeImmutable('2026-09-08'),
            AccountId::of('account-office-supplies'),
            AccountId::of('account-cash'),
            'Office supplies',
            null,
        );
    }

    private function insertAccount(TenantId $tenantId, string $accountId, string $accountType): void
    {
        DB::connection('pgsql')->table(self::ACCOUNT_TABLE)->insert([
            'tenant_id' => $tenantId->toString(),
            'account_id' => $accountId,
            'account_code' => substr(md5($tenantId->toString().$accountId), 0, 10),
            'account_name' => 'Test Account',
            'account_type' => $accountType,
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

        // Narrow, path-scoped migration calls only — mirroring every
        // other integration test's own established convention. A broad,
        // unscoped `artisan migrate` here would apply *every* pending
        // migration (including ones this test never asked for) to the
        // shared long-lived test database for the rest of the suite
        // run, silently changing what other, unrelated test classes'
        // own narrow `Schema::dropIfExists('journals')` migration-
        // reversibility dances encounter (confirmed: this was exactly
        // the proximate cause of a 251-test cascade failure during this
        // module's own development).
        foreach ([
            'database/migrations/2026_09_04_030000_create_accounts_table.php',
            'database/migrations/2026_09_04_150000_create_journals_and_journal_lines_tables.php',
            'database/migrations/2026_09_06_090000_add_correction_chain_to_journals_table.php',
            'database/migrations/2026_09_06_200000_create_audit_events_table.php',
            'database/migrations/2026_09_06_210000_create_journal_evidence_links_table.php',
            'database/migrations/2026_09_06_220000_create_expenses_table.php',
            'database/migrations/2026_09_06_230000_add_financial_date_and_posted_at_to_journals_table.php',
            'database/migrations/2026_09_06_235000_create_incomes_table.php',
            'database/migrations/2026_09_07_050000_create_transfers_table.php',
            'database/migrations/2026_09_07_060000_create_owner_equity_transactions_table.php',
            'database/migrations/2026_09_05_090000_create_posting_idempotency_keys_table.php',
            'database/migrations/2026_09_08_010000_create_tasks_table.php',
            'database/migrations/2026_09_08_020000_create_proposals_table.php',
            'database/migrations/2026_09_08_030000_create_task_transitions_table.php',
        ] as $path) {
            Artisan::call('migrate', ['--database' => 'pgsql', '--path' => $path, '--realpath' => false, '--force' => true]);
        }

        if (! Schema::connection('pgsql')->hasTable('tasks') || ! Schema::connection('pgsql')->hasTable('proposals') || ! Schema::connection('pgsql')->hasTable('task_transitions')) {
            self::$skipReason = 'The tasks/proposals/task_transitions tables did not migrate successfully.';

            return;
        }

        self::$migrated = true;
    }
}
