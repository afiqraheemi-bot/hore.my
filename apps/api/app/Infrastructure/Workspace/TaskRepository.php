<?php

declare(strict_types=1);

namespace App\Infrastructure\Workspace;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\Exception\TaskNotFoundException;
use App\Domain\Workspace\Task;
use App\Domain\Workspace\TaskId;
use App\Domain\Workspace\TaskService;
use App\Domain\Workspace\TaskState;
use App\Domain\Workspace\TaskTransition;
use Illuminate\Database\ConnectionInterface;

/**
 * The persistence boundary for the Task aggregate and its
 * {@see TaskTransition} audit trail (ADR-0009), through the production
 * `tasks` and `task_transitions` tables.
 */
final class TaskRepository
{
    private const TABLE = 'tasks';

    private const TRANSITION_TABLE = 'task_transitions';

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function record(Task $task): void
    {
        $this->connection->table(self::TABLE)->insert([
            'tenant_id' => $task->tenantId()->toString(),
            'task_id' => $task->id()->toString(),
            'state' => $task->state()->name,
            'result_journal_id' => $task->resultJournalId()?->toString(),
            'failure_reason' => $task->failureReason(),
            'completed_at' => $task->completedAt()?->format('Y-m-d H:i:s'),
            'created_at' => $task->createdAt()->format('Y-m-d H:i:s'),
            'updated_at' => $task->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Persists a state-transition result for a caller that already
     * holds exclusive knowledge that no concurrent writer can race it
     * (for example, immediately after a winning
     * {@see transitionIfInState()} call, within the same request).
     * Only `state`, `completed_at`, `result_journal_id`, and
     * `failure_reason` ever change after {@see record()}'s initial
     * insert.
     */
    public function updateState(Task $task): void
    {
        $this->connection->table(self::TABLE)
            ->where('tenant_id', $task->tenantId()->toString())
            ->where('task_id', $task->id()->toString())
            ->update([
                'state' => $task->state()->name,
                'result_journal_id' => $task->resultJournalId()?->toString(),
                'failure_reason' => $task->failureReason(),
                'completed_at' => $task->completedAt()?->format('Y-m-d H:i:s'),
                'updated_at' => now(),
            ]);
    }

    /**
     * The TSK-004 concurrency guard: an atomic compare-and-swap that
     * only succeeds if the Task is still in `$expectedFrom` at the
     * instant of the `UPDATE`. Two concurrent callers racing the same
     * transition can never both succeed — PostgreSQL's own MVCC
     * serializes the two `UPDATE`s to the same row, and only one
     * caller ever observes an affected-row count greater than zero.
     * Mirrors this codebase's own established pattern (P1-3's
     * `PaymentAllocationRepository::softDelete()`,
     * `WHERE deleted_at IS NULL` compare-and-swap).
     *
     * @return bool true if this call won the race and applied the
     *              transition; false if a concurrent request already
     *              moved the Task out of `$expectedFrom` first.
     */
    public function transitionIfInState(Task $task, TaskState $expectedFrom): bool
    {
        $affected = $this->connection->table(self::TABLE)
            ->where('tenant_id', $task->tenantId()->toString())
            ->where('task_id', $task->id()->toString())
            ->where('state', $expectedFrom->name)
            ->update([
                'state' => $task->state()->name,
                'result_journal_id' => $task->resultJournalId()?->toString(),
                'failure_reason' => $task->failureReason(),
                'completed_at' => $task->completedAt()?->format('Y-m-d H:i:s'),
                'updated_at' => now(),
            ]);

        return $affected > 0;
    }

    public function recordTransition(TaskTransition $transition): void
    {
        $this->connection->table(self::TRANSITION_TABLE)->insert([
            'transition_id' => $transition->id(),
            'tenant_id' => $transition->tenantId()->toString(),
            'task_id' => $transition->taskId()->toString(),
            'actor' => $transition->actor()->toString(),
            'from_state' => $transition->fromState()?->name,
            'to_state' => $transition->toState()->name,
            'reason' => $transition->reason(),
            'evidence_reference' => $transition->evidenceReference()?->toString(),
            'created_at' => $transition->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(TenantId $tenantId, TaskId $id): ?Task
    {
        /** @var object{tenant_id: string, task_id: string, state: string, result_journal_id: string|null, failure_reason: string|null, completed_at: string|null, created_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('task_id', $id->toString())
            ->first();

        if ($row === null) {
            return null;
        }

        return self::fromPersisted($row);
    }

    /**
     * @throws TaskNotFoundException if no such Task exists for this
     *                               Tenant.
     */
    public function getById(TenantId $tenantId, TaskId $id): Task
    {
        return $this->findById($tenantId, $id) ?? throw TaskNotFoundException::forId($id);
    }

    /**
     * The pessimistic-locking counterpart to {@see getById()} — issues
     * `SELECT ... FOR UPDATE`, so the row stays locked for the
     * duration of the caller's own transaction. {@see getById()}'s
     * optimistic compare-and-swap ({@see transitionIfInState()})
     * cannot protect a resume from `Executing`, because there is no
     * earlier state-changing transition only one concurrent caller can
     * win — both callers already observe the same `Executing` state.
     * Taking the row lock up front instead makes a second concurrent
     * caller block until the first caller's whole transaction commits,
     * so it re-reads the Task's *post-resume* state rather than racing
     * on the pre-resume one. Used only by
     * {@see TaskService::resume()}.
     *
     * @throws TaskNotFoundException if no such Task exists for this
     *                               Tenant.
     */
    public function getByIdForUpdate(TenantId $tenantId, TaskId $id): Task
    {
        /** @var object{tenant_id: string, task_id: string, state: string, result_journal_id: string|null, failure_reason: string|null, completed_at: string|null, created_at: string}|null $row */
        $row = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('task_id', $id->toString())
            ->lockForUpdate()
            ->first();

        return $row === null ? throw TaskNotFoundException::forId($id) : self::fromPersisted($row);
    }

    /**
     * @return list<Task>
     */
    public function findByTenant(TenantId $tenantId): array
    {
        /** @var list<object{tenant_id: string, task_id: string, state: string, result_journal_id: string|null, failure_reason: string|null, completed_at: string|null, created_at: string}> $rows */
        $rows = $this->connection->table(self::TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->orderByDesc('created_at')
            ->get()
            ->all();

        return array_map(static fn (object $row): Task => self::fromPersisted($row), $rows);
    }

    /**
     * @return list<TaskTransition>
     */
    public function findTransitionsFor(TenantId $tenantId, TaskId $taskId): array
    {
        /** @var list<object{transition_id: string, tenant_id: string, task_id: string, actor: string, from_state: string|null, to_state: string, reason: string|null, evidence_reference: string|null, created_at: string}> $rows */
        $rows = $this->connection->table(self::TRANSITION_TABLE)
            ->where('tenant_id', $tenantId->toString())
            ->where('task_id', $taskId->toString())
            ->orderBy('transition_sequence')
            ->get()
            ->all();

        return array_map(static fn (object $row): TaskTransition => new TaskTransition(
            $row->transition_id,
            TenantId::of($row->tenant_id),
            TaskId::of($row->task_id),
            ActorReference::of($row->actor),
            $row->from_state === null ? null : self::stateFromPersisted($row->from_state),
            self::stateFromPersisted($row->to_state),
            $row->reason,
            $row->evidence_reference === null ? null : EvidenceReference::of($row->evidence_reference),
            new \DateTimeImmutable($row->created_at),
        ), $rows);
    }

    /**
     * @param  object{tenant_id: string, task_id: string, state: string, result_journal_id: string|null, failure_reason: string|null, completed_at: string|null, created_at: string}  $row
     */
    private static function fromPersisted(object $row): Task
    {
        return Task::reconstitute(
            TaskId::of($row->task_id),
            TenantId::of($row->tenant_id),
            self::stateFromPersisted($row->state),
            new \DateTimeImmutable($row->created_at),
            $row->completed_at === null ? null : new \DateTimeImmutable($row->completed_at),
            $row->result_journal_id === null ? null : JournalId::of($row->result_journal_id),
            $row->failure_reason,
        );
    }

    private static function stateFromPersisted(string $value): TaskState
    {
        foreach (TaskState::cases() as $case) {
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new \RuntimeException(sprintf('Unrecognized persisted Task state "%s".', $value));
    }
}
