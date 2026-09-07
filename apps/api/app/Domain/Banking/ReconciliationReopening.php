<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * One historical record of a completed Reconciliation being reopened
 * (M18, SRS BNK-007) — see the owning `reconciliation_reopenings`
 * migration's own docblock for why this is a dedicated, append-only
 * record rather than fields on {@see Reconciliation} itself or a reuse
 * of Accounting Core's `AuditEvent`.
 */
final class ReconciliationReopening
{
    public function __construct(
        private readonly string $id,
        private readonly TenantId $tenantId,
        private readonly ReconciliationId $reconciliationId,
        private readonly string $reason,
        private readonly ActorReference $reopenedBy,
        private readonly \DateTimeImmutable $reopenedAt,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function reconciliationId(): ReconciliationId
    {
        return $this->reconciliationId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function reopenedBy(): ActorReference
    {
        return $this->reopenedBy;
    }

    public function reopenedAt(): \DateTimeImmutable
    {
        return $this->reopenedAt;
    }
}
