<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidMoneyAmountException;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Workspace\CommandType;
use App\Domain\Workspace\Exception\InvalidTaskStateTransitionException;
use App\Domain\Workspace\Exception\ProposalNotFoundException;
use App\Domain\Workspace\Exception\TaskAlreadyTransitionedException;
use App\Domain\Workspace\Exception\TaskNotFoundException;
use App\Domain\Workspace\Exception\TaskSubmissionConflictException;
use App\Domain\Workspace\Proposal;
use App\Domain\Workspace\Task;
use App\Domain\Workspace\TaskDraft;
use App\Domain\Workspace\TaskId;
use App\Domain\Workspace\TaskService;
use App\Domain\Workspace\TaskTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspace\CancelTaskRequest;
use App\Http\Requests\Workspace\ProvideTaskInformationRequest;
use App\Http\Requests\Workspace\RejectTaskRequest;
use App\Http\Requests\Workspace\StoreTaskRequest;
use App\Http\Requests\Workspace\SupersedeTaskRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Workspace\ProposalRepository;
use App\Infrastructure\Workspace\TaskDraftRepository;
use App\Infrastructure\Workspace\TaskRepository;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Workspace/Task lifecycle over HTTP (ADR-0009, WTS-001): the Work
 * Queue ({@see index()}), submitting evidence/instruction as a Task
 * with its Proposal ({@see store()}), Task Detail
 * ({@see show()}), Human Confirmation
 * ({@see approve()}, {@see reject()}, {@see cancel()}), and crash
 * recovery for a Task stranded in `Executing` ({@see resume()}).
 * Mirrors {@see ReconciliationController}'s shape.
 */
final class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly TaskRepository $taskRepository,
        private readonly ProposalRepository $proposalRepository,
        private readonly TaskDraftRepository $taskDraftRepository,
    ) {}

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $tasks = $this->taskRepository->findByTenant($currentTenant->id());

        // Bulk-fetched once for the whole list rather than per Task
        // (`toArray()`'s single-Task call sites below fetch their own
        // instead) — the Work Queue list needs a `summary` per row so
        // it can show what a Task is actually about instead of just
        // its id, and doing that per-row here would be an N+1 query
        // against `proposals`/`task_drafts` for every Task the tenant
        // has ever submitted.
        $proposalsByTask = $this->proposalRepository->getCurrentForTenant($currentTenant->id());
        $draftsByTask = $this->taskDraftRepository->findAllForTenant($currentTenant->id());

        return response()->json(['data' => array_map(
            fn (Task $task): array => $this->toArray($task, $currentTenant, proposalsByTask: $proposalsByTask, draftsByTask: $draftsByTask),
            $tasks,
        )]);
    }

    public function store(StoreTaskRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $idempotencyKeyHeader = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKeyHeader) || $idempotencyKeyHeader === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $evidenceReference = $request->string('evidence_reference')->toString();
        $evidenceReferenceValue = $evidenceReference === '' ? null : EvidenceReference::of($evidenceReference);

        try {
            if ($request->deferringAccountDecision()) {
                $task = $this->taskService->saveForLaterCompletion(
                    $currentTenant->id(),
                    ActorReference::of($user->id),
                    IdempotencyKey::of($idempotencyKeyHeader),
                    CommandType::fromName($request->string('command_type')->toString()),
                    Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
                    new \DateTimeImmutable($request->string('transaction_date')->toString()),
                    $request->string('description')->toString(),
                    $evidenceReferenceValue,
                );
            } else {
                $task = $this->taskService->submit(
                    $currentTenant->id(),
                    ActorReference::of($user->id),
                    IdempotencyKey::of($idempotencyKeyHeader),
                    CommandType::fromName($request->string('command_type')->toString()),
                    Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
                    new \DateTimeImmutable($request->string('transaction_date')->toString()),
                    AccountId::of($request->string('primary_account_id')->toString()),
                    AccountId::of($request->string('secondary_account_id')->toString()),
                    $request->string('description')->toString(),
                    $evidenceReferenceValue,
                );
            }
        } catch (InvalidMoneyAmountException|RejectedAccountReferenceException|TaskSubmissionConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->toArray($task, $currentTenant), 201);
    }

    /**
     * Completes a Task's deferred Account decision — see
     * {@see TaskService::provideInformation()}.
     */
    public function provideInformation(ProvideTaskInformationRequest $request, CurrentTenant $currentTenant, string $taskId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $task = $this->taskService->provideInformation(
                $currentTenant->id(),
                TaskId::of($taskId),
                ActorReference::of($user->id),
                AccountId::of($request->string('primary_account_id')->toString()),
                AccountId::of($request->string('secondary_account_id')->toString()),
            );
        } catch (RejectedAccountReferenceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InvalidTaskStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TaskNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($this->toArray($task, $currentTenant, includeDetail: true));
    }

    /**
     * The edit/supersede correction flow — see
     * {@see TaskService::supersedeAndSubmitCorrection()}.
     */
    public function supersede(SupersedeTaskRequest $request, CurrentTenant $currentTenant, string $taskId): JsonResponse
    {
        $idempotencyKeyHeader = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKeyHeader) || $idempotencyKeyHeader === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $evidenceReference = $request->string('evidence_reference')->toString();

        try {
            $task = $this->taskService->supersedeAndSubmitCorrection(
                $currentTenant->id(),
                TaskId::of($taskId),
                ActorReference::of($user->id),
                $request->string('reason')->toString() === '' ? null : $request->string('reason')->toString(),
                IdempotencyKey::of($idempotencyKeyHeader),
                CommandType::fromName($request->string('command_type')->toString()),
                Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
                new \DateTimeImmutable($request->string('transaction_date')->toString()),
                AccountId::of($request->string('primary_account_id')->toString()),
                AccountId::of($request->string('secondary_account_id')->toString()),
                $request->string('description')->toString(),
                $evidenceReference === '' ? null : EvidenceReference::of($evidenceReference),
            );
        } catch (InvalidMoneyAmountException|RejectedAccountReferenceException|TaskSubmissionConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TaskAlreadyTransitionedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidTaskStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TaskNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($this->toArray($task, $currentTenant, includeDetail: true), 201);
    }

    public function show(CurrentTenant $currentTenant, string $taskId): JsonResponse
    {
        try {
            $task = $this->taskRepository->getById($currentTenant->id(), TaskId::of($taskId));
        } catch (TaskNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($this->toArray($task, $currentTenant, includeDetail: true));
    }

    public function approve(Request $request, CurrentTenant $currentTenant, string $taskId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->transition(fn (): Task => $this->taskService->approve($currentTenant->id(), TaskId::of($taskId), ActorReference::of($user->id)), $currentTenant);
    }

    /**
     * Crash recovery for a Task stranded in `Executing` — see
     * {@see TaskService::resume()}'s own docblock.
     */
    public function resume(Request $request, CurrentTenant $currentTenant, string $taskId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->transition(fn (): Task => $this->taskService->resume($currentTenant->id(), TaskId::of($taskId), ActorReference::of($user->id)), $currentTenant);
    }

    public function reject(RejectTaskRequest $request, CurrentTenant $currentTenant, string $taskId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->transition(fn (): Task => $this->taskService->reject($currentTenant->id(), TaskId::of($taskId), ActorReference::of($user->id), $request->string('reason')->toString()), $currentTenant);
    }

    public function cancel(CancelTaskRequest $request, CurrentTenant $currentTenant, string $taskId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->transition(fn (): Task => $this->taskService->cancel($currentTenant->id(), TaskId::of($taskId), ActorReference::of($user->id), $request->string('reason')->toString()), $currentTenant);
    }

    /**
     * @param  \Closure(): Task  $action
     */
    private function transition(\Closure $action, CurrentTenant $currentTenant): JsonResponse
    {
        try {
            $task = $action();
        } catch (TaskAlreadyTransitionedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (InvalidTaskStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TaskNotFoundException|ProposalNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($this->toArray($task, $currentTenant, includeDetail: true));
    }

    /**
     * @param  array<string, Proposal>|null  $proposalsByTask  Pre-fetched, keyed by Task id — pass when
     *                                                         mapping many Tasks at once ({@see index()}) to
     *                                                         avoid an N+1 query; omit to fetch this one
     *                                                         Task's own Proposal directly.
     * @param  array<string, TaskDraft>|null  $draftsByTask  Same, for `task_drafts`.
     * @return array<string, mixed>
     */
    private function toArray(
        Task $task,
        CurrentTenant $currentTenant,
        bool $includeDetail = false,
        ?array $proposalsByTask = null,
        ?array $draftsByTask = null,
    ): array {
        $data = [
            'id' => $task->id()->toString(),
            'state' => $task->state()->name,
            'created_at' => $task->createdAt()->format(\DateTimeInterface::ATOM),
            'completed_at' => $task->completedAt()?->format(\DateTimeInterface::ATOM),
            'result_journal_id' => $task->resultJournalId()?->toString(),
            'failure_reason' => $task->failureReason(),
            'supersedes_task_id' => $task->supersedesTaskId()?->toString(),
        ];

        $proposal = $proposalsByTask !== null
            ? ($proposalsByTask[$task->id()->toString()] ?? null)
            : $this->currentProposalOrNull($currentTenant, $task->id());

        $draft = $proposal !== null
            ? null
            : ($draftsByTask !== null
                ? ($draftsByTask[$task->id()->toString()] ?? null)
                : $this->taskDraftRepository->findByTask($currentTenant->id(), $task->id()));

        // A short, list-safe summary of what this Task is actually
        // about — every Task has exactly one of these two once
        // submitted, so this is never both-null in practice, only
        // possibly null for a Task somehow observed mid-write.
        $data['summary'] = match (true) {
            $proposal !== null => [
                'command_type' => $proposal->commandType()->name,
                'amount' => $proposal->amount()->toDecimalString(),
                'description' => $proposal->description(),
            ],
            $draft !== null => [
                'command_type' => $draft->commandType()->name,
                'amount' => $draft->amount()->toDecimalString(),
                'description' => $draft->description(),
            ],
            default => null,
        };

        if (! $includeDetail) {
            return $data;
        }

        $data['proposal'] = $proposal !== null ? $this->proposalToArray($proposal) : null;
        $data['draft'] = $draft === null ? null : [
            'command_type' => $draft->commandType()->name,
            'amount' => $draft->amount()->toDecimalString(),
            'transaction_date' => $draft->transactionDate()->format('Y-m-d'),
            'description' => $draft->description(),
            'evidence_reference' => $draft->evidenceReference()?->toString(),
        ];

        $data['transitions'] = array_map(
            fn (TaskTransition $transition): array => [
                'actor' => $transition->actor()->toString(),
                'from_state' => $transition->fromState()?->name,
                'to_state' => $transition->toState()->name,
                'reason' => $transition->reason(),
                'created_at' => $transition->createdAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->taskRepository->findTransitionsFor($currentTenant->id(), $task->id()),
        );

        return $data;
    }

    private function currentProposalOrNull(CurrentTenant $currentTenant, TaskId $taskId): ?Proposal
    {
        try {
            return $this->proposalRepository->getCurrentForTask($currentTenant->id(), $taskId);
        } catch (ProposalNotFoundException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalToArray(Proposal $proposal): array
    {
        return [
            'id' => $proposal->id()->toString(),
            'command_type' => $proposal->commandType()->name,
            'amount' => $proposal->amount()->toDecimalString(),
            'transaction_date' => $proposal->transactionDate()->format('Y-m-d'),
            'primary_account_id' => $proposal->primaryAccountId()->toString(),
            'secondary_account_id' => $proposal->secondaryAccountId()->toString(),
            'description' => $proposal->description(),
            'evidence_reference' => $proposal->evidenceReference()?->toString(),
            'confidence' => $proposal->confidence(),
            'producer_type' => $proposal->producerType()->name,
        ];
    }
}
