<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Period\PeriodClosureRepository;

/**
 * A record of one completed Period closing (AETS-014) — the Tenant is
 * closed "through" this date (inclusive): no ordinary Posting Command
 * may carry a Financial Date on or before it afterward. Immutable:
 * closing again produces a new `PeriodClosure` row, never a mutation of
 * this one — {@see PeriodClosureRepository}
 * has no `update()`.
 */
final class PeriodClosure
{
    public function __construct(
        private readonly TenantId $tenantId,
        private readonly \DateTimeImmutable $closedThroughDate,
        private readonly JournalId $closingJournalId,
        private readonly \DateTimeImmutable $closedAt,
    ) {}

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function closedThroughDate(): \DateTimeImmutable
    {
        return $this->closedThroughDate;
    }

    public function closingJournalId(): JournalId
    {
        return $this->closingJournalId;
    }

    public function closedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }
}
