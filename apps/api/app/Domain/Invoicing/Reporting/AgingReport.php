<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Reporting;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\ReconciliationDifference;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Debtors/Aging report as of a given date (M22, Hasil MVP item 6:
 * "Penghutang dan aging report") — one {@see AgingReportLine} per
 * outstanding Invoice, computed **live** from Invoice/PaymentAllocation
 * data, never from a stored balance (Master Context §10: "Baki
 * authoritative datang daripada lejar"; mirrors
 * {@see ReconciliationDifference}'s own
 * always-recomputed convention).
 */
final class AgingReport
{
    /**
     * @param  list<AgingReportLine>  $lines
     */
    public function __construct(
        private readonly TenantId $tenantId,
        private readonly \DateTimeImmutable $asOfDate,
        private readonly Currency $currency,
        private readonly array $lines,
    ) {}

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function asOfDate(): \DateTimeImmutable
    {
        return $this->asOfDate;
    }

    /**
     * @return list<AgingReportLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalForBucket(AgingBucket $bucket): Money
    {
        $total = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);

        foreach ($this->lines as $line) {
            if ($line->bucket() === $bucket) {
                $total = $total->add($line->outstandingBalance());
            }
        }

        return $total;
    }

    public function grandTotal(): Money
    {
        $total = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);

        foreach ($this->lines as $line) {
            $total = $total->add($line->outstandingBalance());
        }

        return $total;
    }
}
