<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A Trial Balance as of a given date (AETS-009 §6, SRS RPT-004) — one
 * {@see AccountBalance} per Account belonging to the Tenant, cumulative
 * since each Account's own inception, computed exclusively from Posted
 * Journal Lines with `financialDate <= asOfDate` (AETS-009 §5).
 *
 * **Total Debit always exactly equals total Credit (`RPT-007`).** This
 * is not asserted here as a business rule this class enforces — it is
 * the structural, guaranteed consequence of every contributing Journal
 * already being exactly balanced (`JRN-007`) before this class ever
 * sees it. {@see isBalanced()} exists to *prove* that guarantee holds
 * for a specific computed result, not to decide whether it should.
 */
final class TrialBalance
{
    /**
     * @param  list<AccountBalance>  $lines
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
     * @return list<AccountBalance>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalDebit(): Money
    {
        $total = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);

        foreach ($this->lines as $line) {
            $total = $total->add($line->totalDebit());
        }

        return $total;
    }

    public function totalCredit(): Money
    {
        $total = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);

        foreach ($this->lines as $line) {
            $total = $total->add($line->totalCredit());
        }

        return $total;
    }

    public function isBalanced(): bool
    {
        return $this->totalDebit()->equals($this->totalCredit());
    }
}
