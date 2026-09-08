<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Proposal entity (ADR-0009, WTS-001 §5) — the structured record a
 * Task carries into `NeedsReview`. Its payload fields mirror an
 * existing Accounting Command's shape exactly (TSK-003); see
 * {@see CommandType} for why no bespoke payload shape exists.
 *
 * **Immutable and append-only** — a Task that needs a different
 * Proposal gets a new Proposal via `Superseded` chaining (WTS-001 §5),
 * never an edit to this one.
 */
final class Proposal
{
    public function __construct(
        private readonly ProposalId $id,
        private readonly TenantId $tenantId,
        private readonly TaskId $taskId,
        private readonly CommandType $commandType,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly AccountId $primaryAccountId,
        private readonly AccountId $secondaryAccountId,
        private readonly string $description,
        private readonly ?EvidenceReference $evidenceReference,
        private readonly ?float $confidence,
        private readonly ActorReference $producerReference,
        private readonly ProposalProducerType $producerType,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public function id(): ProposalId
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

    public function primaryAccountId(): AccountId
    {
        return $this->primaryAccountId;
    }

    public function secondaryAccountId(): AccountId
    {
        return $this->secondaryAccountId;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function evidenceReference(): ?EvidenceReference
    {
        return $this->evidenceReference;
    }

    public function confidence(): ?float
    {
        return $this->confidence;
    }

    public function producerReference(): ActorReference
    {
        return $this->producerReference;
    }

    public function producerType(): ProposalProducerType
    {
        return $this->producerType;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
