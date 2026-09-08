<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Banking\ReconciliationReopening;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * One immutable audit record of a Task state transition (WTS-001 §6,
 * TSK-001) — the Task-lifecycle equivalent of
 * {@see ReconciliationReopening}, generalized to
 * cover every transition rather than one specific action. See the
 * owning `task_transitions` migration's own docblock for why this is
 * a dedicated table, never `AuditEvent` (AETS-010).
 */
final class TaskTransition
{
    public function __construct(
        private readonly string $id,
        private readonly TenantId $tenantId,
        private readonly TaskId $taskId,
        private readonly ActorReference $actor,
        private readonly ?TaskState $fromState,
        private readonly TaskState $toState,
        private readonly ?string $reason,
        private readonly ?EvidenceReference $evidenceReference,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function taskId(): TaskId
    {
        return $this->taskId;
    }

    public function actor(): ActorReference
    {
        return $this->actor;
    }

    public function fromState(): ?TaskState
    {
        return $this->fromState;
    }

    public function toState(): TaskState
    {
        return $this->toState;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function evidenceReference(): ?EvidenceReference
    {
        return $this->evidenceReference;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
