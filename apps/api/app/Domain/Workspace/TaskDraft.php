<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A Task's deferred submission payload (WTS-001 v3.0.0, TSK-013) — the
 * fields a submitter already supplied while the Account decision is
 * still outstanding and the Task sits in `NeedsInformation`. Carries
 * no Account reference at all; that is exactly the decision this
 * class exists to hold open.
 *
 * **Immutable and never edited**, mirroring {@see Proposal}'s own
 * convention: {@see TaskService::provideInformation()} reads this
 * once, to construct the real {@see Proposal} the supplied Account
 * references complete, and never writes a second Draft for the same
 * Task.
 */
final class TaskDraft
{
    public function __construct(
        private readonly TenantId $tenantId,
        private readonly TaskId $taskId,
        private readonly CommandType $commandType,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly string $description,
        private readonly ?EvidenceReference $evidenceReference,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function taskId(): TaskId
    {
        return $this->taskId;
    }

    public function commandType(): CommandType
    {
        return $this->commandType;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function transactionDate(): \DateTimeImmutable
    {
        return $this->transactionDate;
    }

    public function description(): string
    {
        return $this->description;
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
