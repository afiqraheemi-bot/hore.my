<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Exception\InvalidMoneyAmountException;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\Exception\RejectedClosedPeriodPostingException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Posting\PostingCommandTransactionalExecutor;
use App\Domain\Banking\ReconciliationService;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\Exception\InvalidExpenseAccountTypeException;
use App\Domain\Transactions\Expense\Exception\InvalidPaymentAccountTypeException;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Domain\Transactions\Expense\RecordExpenseCommand;
use App\Domain\Transactions\Income\Exception\InvalidDepositAccountTypeException;
use App\Domain\Transactions\Income\Exception\InvalidIncomeAccountTypeException;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Domain\Transactions\Income\RecordIncomeCommand;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidCashAccountTypeException;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidEquityAccountTypeException;
use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionRecordingService;
use App\Domain\Transactions\OwnerEquity\RecordOwnerEquityTransactionCommand;
use App\Domain\Transactions\Transfer\Exception\InvalidTransferAccountTypeException;
use App\Domain\Transactions\Transfer\Exception\SameAccountTransferException;
use App\Domain\Transactions\Transfer\RecordTransferCommand;
use App\Domain\Transactions\Transfer\TransferId;
use App\Domain\Transactions\Transfer\TransferRecordingService;
use App\Domain\Workspace\Exception\InvalidTaskStateTransitionException;
use App\Domain\Workspace\Exception\TaskAlreadyTransitionedException;
use App\Domain\Workspace\Exception\TaskSubmissionConflictException;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Support\DeterministicIdempotentId;
use App\Infrastructure\Workspace\ProposalRepository;
use App\Infrastructure\Workspace\TaskRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The application service orchestrating a Task's lifecycle (ADR-0009,
 * WTS-001) — the only class that persists {@see Task}/{@see Proposal}
 * state or calls into Accounting Core; {@see Task} itself only
 * enforces state-machine validity. Mirrors
 * {@see ReconciliationService}'s own role exactly.
 *
 * **This is Phase D's "Non-AI Workflow Shell."** Every Proposal this
 * service produces is {@see ProposalProducerType::Human} — a human
 * describes what to record, and {@see submit()} folds Received ->
 * Processing -> NeedsReview into one synchronous call because there is
 * no real interpretation step yet. The full state machine (WTS-001 §3)
 * and its concurrency/audit guarantees are still exercised end-to-end,
 * so a future AI-produced Proposal (WTS-004) needs no change to
 * {@see approve()}, {@see reject()}, or {@see cancel()} — only a new
 * way to reach `NeedsReview` from `Processing`, asynchronously, with
 * {@see ProposalProducerType::AI}.
 *
 * **{@see approve()} never touches Accounting Core directly with a
 * bespoke code path.** It calls the exact same
 * `*RecordingService::record()` entry point manual entry's HTTP
 * controllers already call (ADR-0009 §Decision) — see
 * {@see executeCommand()}.
 *
 * **2026-09-08 reliability closure (post-implementation QA):** every
 * multi-write sequence in this class now runs inside one
 * `Connection::transaction()` — {@see applyTransition()}'s state
 * update and its `TSK-001` audit record are one atomic unit, and
 * {@see submit()}'s whole Task+Proposal creation sequence is one
 * atomic unit — proven by forced-constraint-failure fault-injection
 * tests mirroring {@see PostingCommandTransactionalExecutor}'s
 * own established pattern. {@see submit()} also now detects a
 * conflicting idempotency-key reuse ({@see TaskSubmissionConflictException}),
 * and {@see resume()} recovers a Task stranded in `Executing` by a
 * prior crash — both were real gaps this class shipped without.
 */
final class TaskService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TaskRepository $taskRepository,
        private readonly ProposalRepository $proposalRepository,
        private readonly ExpenseRecordingService $expenseService,
        private readonly IncomeRecordingService $incomeService,
        private readonly TransferRecordingService $transferService,
        private readonly OwnerEquityTransactionRecordingService $ownerEquityService,
    ) {}

    /**
     * Creates a Task and its initial, human-authored Proposal in one
     * atomic database transaction, landing the Task in `NeedsReview`
     * ready for Human Confirmation ({@see approve()}).
     *
     * `$idempotencyKey` makes Task creation itself safely retryable,
     * mirroring every existing Command-producing HTTP endpoint
     * ({@see ExpenseController}'s own
     * docblock explains why): `TaskId` is derived deterministically
     * from `(Tenant, Idempotency-Key)`, never freshly random. A retry
     * that finds a Task already recorded under that identifier, with
     * an unchanged Proposal payload, returns it unchanged rather than
     * creating a second one; a retry whose payload has materially
     * changed is rejected instead of silently returning the stale
     * original (TSK-003's own parity requirement applied to the
     * request itself).
     *
     * **Concurrent first-submission is a real race, not just a
     * concurrent retry.** Two requests under the same, never-before-
     * used Idempotency Key can both observe {@see findById()} return
     * `null` before either has committed, then both attempt to
     * `INSERT` the same deterministic `TaskId`. Mirrors
     * {@see PostingCommandTransactionalExecutor}'s own established
     * technique exactly: the loser's `INSERT` fails on the real
     * `tasks_pkey` constraint (never an application-level pre-check),
     * and is resolved identically to an ordinary retry — a matching
     * payload replays the winner's Task, a conflicting one is
     * rejected — rather than surfacing a raw, unhandled database
     * constraint-violation error.
     *
     * @throws TaskSubmissionConflictException if `$idempotencyKey` was
     *                                         already used for a materially different Proposal.
     */
    public function submit(
        TenantId $tenantId,
        ActorReference $actor,
        IdempotencyKey $idempotencyKey,
        CommandType $commandType,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $primaryAccountId,
        AccountId $secondaryAccountId,
        string $description,
        ?EvidenceReference $evidenceReference,
    ): Task {
        $taskId = TaskId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'task'));

        $existing = $this->taskRepository->findById($tenantId, $taskId);

        if ($existing !== null) {
            return $this->replayOrConflict($tenantId, $taskId, $idempotencyKey, $commandType, $amount, $transactionDate, $primaryAccountId, $secondaryAccountId, $description, $existing);
        }

        try {
            return $this->connection->transaction(function () use (
                $taskId, $tenantId, $actor, $idempotencyKey, $commandType, $amount,
                $transactionDate, $primaryAccountId, $secondaryAccountId, $description, $evidenceReference,
            ): Task {
                $now = new \DateTimeImmutable;
                $task = Task::receive($taskId, $tenantId, $now);
                $this->taskRepository->record($task);
                $this->taskRepository->recordTransition(new TaskTransition(
                    (string) Str::uuid(), $tenantId, $task->id(), $actor, null, $task->state(), null, $evidenceReference, $now,
                ));

                $task = $this->applyTransition($task, static fn (Task $t): Task => $t->startProcessing(), $actor, null, $evidenceReference);

                $proposal = new Proposal(
                    ProposalId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'proposal')),
                    $tenantId,
                    $task->id(),
                    $commandType,
                    $amount,
                    $transactionDate,
                    $primaryAccountId,
                    $secondaryAccountId,
                    $description,
                    $evidenceReference,
                    null,
                    $actor,
                    ProposalProducerType::Human,
                    new \DateTimeImmutable,
                );
                $this->proposalRepository->record($proposal);

                return $this->applyTransition($task, static fn (Task $t): Task => $t->moveToReview(), $actor, null, $evidenceReference);
            });
        } catch (QueryException $e) {
            if (! $this->isTaskPrimaryKeyViolation($e)) {
                throw $e;
            }

            // Lost the race: another request already committed this
            // exact TaskId between our findById() check and our own
            // INSERT. Resolve it exactly as an ordinary retry would.
            $winner = $this->taskRepository->getById($tenantId, $taskId);

            return $this->replayOrConflict($tenantId, $taskId, $idempotencyKey, $commandType, $amount, $transactionDate, $primaryAccountId, $secondaryAccountId, $description, $winner);
        }
    }

    /**
     * TSK-003 applied to the *request*: a `Task` already recorded
     * under `$idempotencyKey` is a safe replay only if its Proposal
     * payload is unchanged from the incoming request.
     *
     * @throws TaskSubmissionConflictException if the payloads differ.
     */
    private function replayOrConflict(
        TenantId $tenantId,
        TaskId $taskId,
        IdempotencyKey $idempotencyKey,
        CommandType $commandType,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $primaryAccountId,
        AccountId $secondaryAccountId,
        string $description,
        Task $existing,
    ): Task {
        $existingProposal = $this->proposalRepository->getCurrentForTask($tenantId, $taskId);

        if (! $this->proposalMatchesRequest($existingProposal, $commandType, $amount, $transactionDate, $primaryAccountId, $secondaryAccountId, $description)) {
            throw TaskSubmissionConflictException::forKey($tenantId, $idempotencyKey);
        }

        return $existing;
    }

    private function isTaskPrimaryKeyViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505' && str_contains($e->getMessage(), 'tasks_pkey');
    }

    /**
     * Human Confirmation: approves the Task's current Proposal and, in
     * the same call, submits the resulting Accounting Command and
     * records the outcome (`Completed` or `Failed`).
     *
     * **One transaction boundary for the whole approval act, not two
     * separate ones.** An earlier version of this method persisted
     * `NeedsReview -> Approved` and `Approved -> Executing` as two
     * independent {@see applyTransition()} calls — a crash between
     * them left a Task durably stranded in `Approved` with no recovery
     * path at all ({@see resume()} only ever handled `Executing`).
     * Taking the same `SELECT ... FOR UPDATE` row lock
     * {@see resume()} uses, for the *entire* call, closes that gap
     * two ways at once: a crash before the lock's transaction commits
     * rolls everything back to `NeedsReview` (the stuck-`Approved`
     * state becomes unreachable going forward), and if this method is
     * ever invoked against a Task already sitting in `Approved` — a
     * row written before this fix existed, or any other reason — it
     * resumes from exactly that point rather than requiring a second,
     * separate recovery action.
     *
     * @throws InvalidTaskStateTransitionException if the Task is in
     *                                             neither `NeedsReview` nor `Approved`.
     */
    public function approve(TenantId $tenantId, TaskId $taskId, ActorReference $actor): Task
    {
        return $this->connection->transaction(function () use ($tenantId, $taskId, $actor): Task {
            $task = $this->taskRepository->getByIdForUpdate($tenantId, $taskId);
            $proposal = $this->proposalRepository->getCurrentForTask($tenantId, $taskId);

            if ($task->state() === TaskState::NeedsReview) {
                $task = $this->applyTransition($task, static fn (Task $t): Task => $t->approve(), $actor);
            }

            if ($task->state() === TaskState::Approved) {
                $task = $this->applyTransition($task, static fn (Task $t): Task => $t->startExecuting(), $actor);
            }

            if ($task->state() !== TaskState::Executing) {
                throw InvalidTaskStateTransitionException::forTransition($taskId, $task->state(), 'approve');
            }

            return $this->executeAndFinalize($task, $tenantId, $actor, $proposal);
        });
    }

    /**
     * Crash recovery for a Task stranded in `Executing` (WTS-001 §3:
     * "a Task should not normally be observed resting in this state —
     * it exists to make a crash mid-flight detectable and
     * recoverable"). Re-attempts the Accounting Command submission
     * `executeCommand()` already derives deterministically from the
     * Proposal's own id, so a resume after the Command actually did
     * post is a safe, idempotent replay (AETS-007) rather than a
     * duplicate — this call never risks double-posting regardless of
     * how far the interrupted attempt actually got.
     *
     * **Pessimistic locking, not the optimistic CAS every other
     * transition uses.** {@see applyTransition()}'s compare-and-swap
     * only protects a transition that starts from a state only one
     * concurrent caller can be first to leave (e.g. `NeedsReview`).
     * Two concurrent `resume()` calls both already observe `Executing`
     * — there is no earlier "first to leave" step to race on. This
     * method instead takes a `SELECT ... FOR UPDATE` row lock
     * ({@see TaskRepository::getByIdForUpdate()})
     * for the whole transaction, so a second concurrent caller blocks
     * until the first one's transaction (including its own nested
     * `Executing -> Completed`/`Failed` transition) has committed, then
     * re-reads the Task's *post-resume* state rather than racing on
     * the stale pre-resume one.
     *
     * @throws InvalidTaskStateTransitionException if the Task is not
     *                                             currently `Executing`.
     */
    public function resume(TenantId $tenantId, TaskId $taskId, ActorReference $actor): Task
    {
        return $this->connection->transaction(function () use ($tenantId, $taskId, $actor): Task {
            $task = $this->taskRepository->getByIdForUpdate($tenantId, $taskId);

            if ($task->state() !== TaskState::Executing) {
                throw InvalidTaskStateTransitionException::forTransition($taskId, $task->state(), 'resume');
            }

            $proposal = $this->proposalRepository->getCurrentForTask($tenantId, $taskId);

            return $this->executeAndFinalize($task, $tenantId, $actor, $proposal);
        });
    }

    /**
     * @throws TaskAlreadyTransitionedException if a concurrent request
     *                                          already transitioned this Task first.
     */
    public function reject(TenantId $tenantId, TaskId $taskId, ActorReference $actor, ?string $reason): Task
    {
        $task = $this->taskRepository->getById($tenantId, $taskId);

        return $this->applyTransition($task, static fn (Task $t): Task => $t->reject(), $actor, $reason);
    }

    /**
     * @throws TaskAlreadyTransitionedException if a concurrent request
     *                                          already transitioned this Task first.
     */
    public function cancel(TenantId $tenantId, TaskId $taskId, ActorReference $actor, ?string $reason): Task
    {
        $task = $this->taskRepository->getById($tenantId, $taskId);

        return $this->applyTransition($task, static fn (Task $t): Task => $t->cancel(), $actor, $reason);
    }

    /**
     * Shared tail for {@see approve()} and {@see resume()}: submits
     * the Accounting Command and records `Completed` or `Failed`.
     * `$task` must already be `Executing`.
     *
     * The catch list is the exact union of every exception the five
     * `*RecordingService`s' own HTTP controllers already catch and
     * surface verbatim as a 422 response body — this is deliberately
     * narrow, not `\RuntimeException`/`\InvalidArgumentException`
     * broadly: only these specific, already-designed-to-be-user-safe
     * business rejections are ever stored as `Task::failureReason()`
     * and shown to a user. Anything else (a genuine bug, an
     * infrastructure error) propagates uncaught rather than being
     * silently absorbed and mis-displayed as an ordinary rejection.
     */
    private function executeAndFinalize(Task $task, TenantId $tenantId, ActorReference $actor, Proposal $proposal): Task
    {
        try {
            $journalId = $this->executeCommand($tenantId, $actor, $proposal);
        } catch (
            RejectedAccountReferenceException|
            RejectedClosedPeriodPostingException|
            RejectedConflictingIdempotencyReuseException|
            InvalidMoneyAmountException|
            InvalidExpenseAccountTypeException|
            InvalidPaymentAccountTypeException|
            InvalidIncomeAccountTypeException|
            InvalidDepositAccountTypeException|
            InvalidTransferAccountTypeException|
            SameAccountTransferException|
            InvalidEquityAccountTypeException|
            InvalidCashAccountTypeException $e
        ) {
            return $this->applyTransition($task, static fn (Task $t): Task => $t->fail($e->getMessage()), $actor, $e->getMessage());
        }

        $completedAt = new \DateTimeImmutable;

        return $this->applyTransition($task, static fn (Task $t): Task => $t->complete($journalId, $completedAt), $actor);
    }

    /**
     * The single choke point every state transition in this service
     * passes through: computes the transition in memory (which may
     * itself reject an invalid transition per TSK-002), then persists
     * it only if the Task is still, at the instant of the `UPDATE`, in
     * the exact state this call observed it in (TSK-004). A concurrent
     * winner's write is never silently overwritten or lost — the
     * loser observes {@see TaskAlreadyTransitionedException} instead.
     *
     * The compare-and-swap `UPDATE` and its `TSK-001` audit record are
     * one atomic database transaction: either both persist, or
     * neither does — a Task's `state` column can never disagree with
     * its own transition history.
     *
     * @param  callable(Task): Task  $transition
     *
     * @throws TaskAlreadyTransitionedException if a concurrent request
     *                                          already transitioned this Task first.
     */
    private function applyTransition(Task $current, callable $transition, ActorReference $actor, ?string $reason = null, ?EvidenceReference $evidenceReference = null): Task
    {
        $fromState = $current->state();
        $next = $transition($current);

        return $this->connection->transaction(function () use ($current, $next, $fromState, $actor, $reason, $evidenceReference): Task {
            if (! $this->taskRepository->transitionIfInState($next, $fromState)) {
                throw TaskAlreadyTransitionedException::forId($current->id());
            }

            $this->taskRepository->recordTransition(new TaskTransition(
                (string) Str::uuid(),
                $next->tenantId(),
                $next->id(),
                $actor,
                $fromState,
                $next->state(),
                $reason,
                $evidenceReference,
                new \DateTimeImmutable,
            ));

            return $next;
        });
    }

    /**
     * TSK-003 applied to the *request*, not just the resulting
     * Command: a retried `submit()` call under an already-used
     * Idempotency Key is only a safe replay if every field of the
     * proposed Proposal is unchanged from the original.
     */
    private function proposalMatchesRequest(
        Proposal $existing,
        CommandType $commandType,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $primaryAccountId,
        AccountId $secondaryAccountId,
        string $description,
    ): bool {
        return $existing->commandType() === $commandType
            && $existing->amount()->equals($amount)
            && $existing->transactionDate()->format('Y-m-d') === $transactionDate->format('Y-m-d')
            && $existing->primaryAccountId()->equals($primaryAccountId)
            && $existing->secondaryAccountId()->equals($secondaryAccountId)
            && $existing->description() === $description;
    }

    /**
     * Translates an approved Proposal into the exact Accounting
     * Command manual entry would produce for the same input (TSK-003),
     * through the exact same `*RecordingService` entry point (ADR-0009
     * §Decision). The Proposal's own `id()` is reused as the resulting
     * Command's Idempotency Key (see {@see ProposalId}'s own
     * docblock), so Accounting Core's own idempotency protection
     * (AETS-007) covers a re-executed `Executing` step for free —
     * including a {@see resume()} call after a crash.
     *
     * Account-field mapping mirrors `AppComposer.vue`'s own, already
     * audited primary/secondary convention (2026-09-11 remediation)
     * exactly, per Command type: Expense (primary=expense, secondary=
     * payment), Income (primary=income, secondary=deposit), Transfer
     * (primary=source, secondary=destination), Capital Contribution
     * and Owner Drawing (primary=cash, secondary=equity — note
     * {@see RecordOwnerEquityTransactionCommand}'s own constructor
     * takes `equityAccountId` before `cashAccountId`, the reverse
     * order).
     */
    private function executeCommand(TenantId $tenantId, ActorReference $actor, Proposal $proposal): JournalId
    {
        $idempotencyKey = IdempotencyKey::of($proposal->id()->toString());
        $journalId = JournalId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'journal'));

        return match ($proposal->commandType()) {
            CommandType::Expense => $this->expenseService->record(new RecordExpenseCommand(
                ExpenseId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'expense')),
                $journalId,
                $idempotencyKey,
                $tenantId,
                $actor,
                $proposal->amount(),
                $proposal->transactionDate(),
                $proposal->primaryAccountId(),
                $proposal->secondaryAccountId(),
                $proposal->description(),
                $proposal->evidenceReference(),
            ))->expense()->journalId(),

            CommandType::Income => $this->incomeService->record(new RecordIncomeCommand(
                IncomeId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'income')),
                $journalId,
                $idempotencyKey,
                $tenantId,
                $actor,
                $proposal->amount(),
                $proposal->transactionDate(),
                $proposal->primaryAccountId(),
                $proposal->secondaryAccountId(),
                $proposal->description(),
                $proposal->evidenceReference(),
            ))->income()->journalId(),

            CommandType::Transfer => $this->transferService->record(new RecordTransferCommand(
                TransferId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'transfer')),
                $journalId,
                $idempotencyKey,
                $tenantId,
                $actor,
                $proposal->amount(),
                $proposal->transactionDate(),
                $proposal->primaryAccountId(),
                $proposal->secondaryAccountId(),
                $proposal->description(),
                $proposal->evidenceReference(),
            ))->transfer()->journalId(),

            CommandType::CapitalContribution => $this->ownerEquityService->record(new RecordOwnerEquityTransactionCommand(
                OwnerEquityTransactionId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'owner-equity')),
                $journalId,
                $idempotencyKey,
                $tenantId,
                $actor,
                OwnerEquityMovementType::Contribution,
                $proposal->amount(),
                $proposal->transactionDate(),
                $proposal->secondaryAccountId(),
                $proposal->primaryAccountId(),
                $proposal->description(),
                $proposal->evidenceReference(),
            ))->transaction()->journalId(),

            CommandType::OwnerDrawing => $this->ownerEquityService->record(new RecordOwnerEquityTransactionCommand(
                OwnerEquityTransactionId::of(DeterministicIdempotentId::derive($tenantId, $idempotencyKey, 'owner-equity')),
                $journalId,
                $idempotencyKey,
                $tenantId,
                $actor,
                OwnerEquityMovementType::Drawing,
                $proposal->amount(),
                $proposal->transactionDate(),
                $proposal->secondaryAccountId(),
                $proposal->primaryAccountId(),
                $proposal->description(),
                $proposal->evidenceReference(),
            ))->transaction()->journalId(),
        };
    }
}
