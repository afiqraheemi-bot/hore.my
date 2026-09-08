<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Banking\ReconciliationService;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Domain\Transactions\Expense\RecordExpenseCommand;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Domain\Transactions\Income\RecordIncomeCommand;
use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionRecordingService;
use App\Domain\Transactions\OwnerEquity\RecordOwnerEquityTransactionCommand;
use App\Domain\Transactions\Transfer\RecordTransferCommand;
use App\Domain\Transactions\Transfer\TransferId;
use App\Domain\Transactions\Transfer\TransferRecordingService;
use App\Domain\Workspace\Exception\TaskAlreadyTransitionedException;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Support\DeterministicIdempotentId;
use App\Infrastructure\Workspace\ProposalRepository;
use App\Infrastructure\Workspace\TaskRepository;
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
 */
final class TaskService
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly ProposalRepository $proposalRepository,
        private readonly ExpenseRecordingService $expenseService,
        private readonly IncomeRecordingService $incomeService,
        private readonly TransferRecordingService $transferService,
        private readonly OwnerEquityTransactionRecordingService $ownerEquityService,
    ) {}

    /**
     * Creates a Task and its initial, human-authored Proposal in one
     * call, landing the Task in `NeedsReview` ready for Human
     * Confirmation ({@see approve()}).
     *
     * `$idempotencyKey` makes Task creation itself safely retryable,
     * mirroring every existing Command-producing HTTP endpoint
     * ({@see ExpenseController}'s own
     * docblock explains why): `TaskId` is derived deterministically
     * from `(Tenant, Idempotency-Key)`, never freshly random, and a
     * retry that finds a Task already recorded under that identifier
     * returns it unchanged rather than creating a second one.
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
            return $existing;
        }

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
    }

    /**
     * Human Confirmation: approves the Task's current Proposal and, in
     * the same call, submits the resulting Accounting Command and
     * records the outcome (`Completed` or `Failed`) — see WTS-001 §3
     * on why `Executing` should normally be observed only transiently.
     *
     * @throws TaskAlreadyTransitionedException if a concurrent request
     *                                          already transitioned this Task first (TSK-004).
     */
    public function approve(TenantId $tenantId, TaskId $taskId, ActorReference $actor): Task
    {
        $task = $this->taskRepository->getById($tenantId, $taskId);
        $proposal = $this->proposalRepository->getCurrentForTask($tenantId, $taskId);

        $task = $this->applyTransition($task, static fn (Task $t): Task => $t->approve(), $actor);
        $task = $this->applyTransition($task, static fn (Task $t): Task => $t->startExecuting(), $actor);

        try {
            $journalId = $this->executeCommand($tenantId, $actor, $proposal);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return $this->applyTransition($task, static fn (Task $t): Task => $t->fail($e->getMessage()), $actor, $e->getMessage());
        }

        $completedAt = new \DateTimeImmutable;

        return $this->applyTransition($task, static fn (Task $t): Task => $t->complete($journalId, $completedAt), $actor);
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
     * The single choke point every state transition in this service
     * passes through: computes the transition in memory (which may
     * itself reject an invalid transition per TSK-002), then persists
     * it only if the Task is still, at the instant of the `UPDATE`, in
     * the exact state this call observed it in (TSK-004). A concurrent
     * winner's write is never silently overwritten or lost — the
     * loser observes {@see TaskAlreadyTransitionedException} instead.
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
    }

    /**
     * Translates an approved Proposal into the exact Accounting
     * Command manual entry would produce for the same input (TSK-003),
     * through the exact same `*RecordingService` entry point (ADR-0009
     * §Decision). The Proposal's own `id()` is reused as the resulting
     * Command's Idempotency Key (see {@see ProposalId}'s own
     * docblock), so Accounting Core's own idempotency protection
     * (AETS-007) covers a re-executed `Executing` step for free.
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
