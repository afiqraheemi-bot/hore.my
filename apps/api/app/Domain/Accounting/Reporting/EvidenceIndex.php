<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Evidence Index for a given Period (AETS-009 §10, SRS RPT-008) —
 * every Posted Journal belonging to the Tenant with `financialDate`
 * inside the Period, as an {@see EvidenceIndexEntry} each.
 */
final class EvidenceIndex
{
    /**
     * @param  list<EvidenceIndexEntry>  $entries
     */
    public function __construct(
        private readonly TenantId $tenantId,
        private readonly \DateTimeImmutable $periodStart,
        private readonly \DateTimeImmutable $periodEnd,
        private readonly array $entries,
    ) {}

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function periodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function periodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    /**
     * @return list<EvidenceIndexEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
