<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Workspace\Exception\InvalidTaskStateTransitionException;
use App\Infrastructure\Workspace\TaskRepository;

/**
 * The Task aggregate (ADR-0009, WTS-001) — owns the lifecycle state
 * defined in WTS-001 §3 and enforces exactly the transition table in
 * WTS-001 §4 (TSK-002). Every method below corresponds to one row of
 * that table; no other transition exists.
 *
 * **Immutable — every transition returns a new instance**, mirroring
 * this codebase's established convention ({@see
 * \App\Domain\Banking\Reconciliation}, `Journal`'s own Draft/Posted
 * distinction). Only state-machine validity is enforced here; this
 * class has no access to the Accounting Core and never calls it —
 * that orchestration is {@see TaskService}'s job (ADR-0009 §Decision).
 *
 * **This class never writes to `task_transitions`.** Producing the
 * TSK-001 audit record for each transition is
 * {@see TaskRepository}'s job, driven by
 * {@see TaskService} — mirroring how {@see
 * \App\Domain\Banking\Reconciliation} has no knowledge of
 * `ReconciliationReopening` either.
 */
final class Task
{
    private function __construct(
        private readonly TaskId $id,
        private readonly TenantId $tenantId,
        private readonly TaskState $state,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?\DateTimeImmutable $completedAt,
        private readonly ?JournalId $resultJournalId,
        private readonly ?string $failureReason,
        private readonly ?TaskId $supersedesTaskId,
    ) {}

    /**
     * `$supersedesTaskId` (TSK-014) names the Task this one corrects,
     * when it is created as a replacement for one just superseded via
     * {@see TaskService::supersedeAndSubmitCorrection()}. `null` for
     * every ordinary, non-correction submission.
     */
    public static function receive(TaskId $id, TenantId $tenantId, \DateTimeImmutable $createdAt, ?TaskId $supersedesTaskId = null): self
    {
        return new self($id, $tenantId, TaskState::Received, $createdAt, null, null, null, $supersedesTaskId);
    }

    public static function reconstitute(
        TaskId $id,
        TenantId $tenantId,
        TaskState $state,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $completedAt,
        ?JournalId $resultJournalId,
        ?string $failureReason,
        ?TaskId $supersedesTaskId = null,
    ): self {
        return new self($id, $tenantId, $state, $createdAt, $completedAt, $resultJournalId, $failureReason, $supersedesTaskId);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is `Received`.
     */
    public function startProcessing(): self
    {
        $this->assertState(TaskState::Received, 'start processing');

        return $this->with(TaskState::Processing);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is `Processing`.
     */
    public function needsInformation(): self
    {
        $this->assertState(TaskState::Processing, 'require information');

        return $this->with(TaskState::NeedsInformation);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is
     *                                             `NeedsInformation`.
     */
    public function resumeProcessing(): self
    {
        $this->assertState(TaskState::NeedsInformation, 'resume processing');

        return $this->with(TaskState::Processing);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is `Processing`.
     */
    public function moveToReview(): self
    {
        $this->assertState(TaskState::Processing, 'move to review');

        return $this->with(TaskState::NeedsReview);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is
     *                                             `NeedsReview`.
     */
    public function approve(): self
    {
        $this->assertState(TaskState::NeedsReview, 'approve');

        return $this->with(TaskState::Approved);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is `Approved`.
     */
    public function startExecuting(): self
    {
        $this->assertState(TaskState::Approved, 'start executing');

        return $this->with(TaskState::Executing);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is `Executing`.
     */
    public function complete(JournalId $resultJournalId, \DateTimeImmutable $completedAt): self
    {
        $this->assertState(TaskState::Executing, 'complete');

        return new self($this->id, $this->tenantId, TaskState::Completed, $this->createdAt, $completedAt, $resultJournalId, null, $this->supersedesTaskId);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is `Executing`.
     */
    public function fail(string $reason): self
    {
        $this->assertState(TaskState::Executing, 'fail');

        return new self($this->id, $this->tenantId, TaskState::Failed, $this->createdAt, null, null, $reason, $this->supersedesTaskId);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is
     *                                             `NeedsReview`.
     */
    public function reject(): self
    {
        $this->assertState(TaskState::NeedsReview, 'reject');

        return $this->with(TaskState::Rejected);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is
     *                                             `NeedsReview`.
     */
    public function supersede(): self
    {
        $this->assertState(TaskState::NeedsReview, 'supersede');

        return $this->with(TaskState::Superseded);
    }

    /**
     * @throws InvalidTaskStateTransitionException unless the current
     *                                             state is one of
     *                                             `Received`,
     *                                             `Processing`,
     *                                             `NeedsInformation`,
     *                                             or `NeedsReview`.
     */
    public function cancel(): self
    {
        if (! in_array($this->state, [TaskState::Received, TaskState::Processing, TaskState::NeedsInformation, TaskState::NeedsReview], true)) {
            throw InvalidTaskStateTransitionException::forTransition($this->id, $this->state, 'cancel');
        }

        return $this->with(TaskState::Cancelled);
    }

    public function id(): TaskId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function state(): TaskState
    {
        return $this->state;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function resultJournalId(): ?JournalId
    {
        return $this->resultJournalId;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function supersedesTaskId(): ?TaskId
    {
        return $this->supersedesTaskId;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    /**
     * @throws InvalidTaskStateTransitionException if the current state
     *                                             is not `$expected`.
     */
    private function assertState(TaskState $expected, string $attemptedTransition): void
    {
        if ($this->state !== $expected) {
            throw InvalidTaskStateTransitionException::forTransition($this->id, $this->state, $attemptedTransition);
        }
    }

    private function with(TaskState $state): self
    {
        return new self($this->id, $this->tenantId, $state, $this->createdAt, $this->completedAt, $this->resultJournalId, $this->failureReason, $this->supersedesTaskId);
    }
}
