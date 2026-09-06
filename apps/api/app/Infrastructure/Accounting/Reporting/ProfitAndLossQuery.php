<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Reporting\ProfitAndLossStatement;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Computes a {@see ProfitAndLossStatement} for a given Period (AETS-009
 * §7, SRS RPT-001) by delegating Revenue and Expense Accounts, each
 * bounded by the Period, to {@see AccountBalanceAggregator} — a period
 * flow, never cumulative since inception.
 */
final class ProfitAndLossQuery
{
    public function __construct(
        private readonly AccountBalanceAggregator $aggregator,
    ) {}

    public function forPeriod(TenantId $tenantId, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): ProfitAndLossStatement
    {
        $revenueLines = $this->aggregator->aggregate($tenantId, $periodStart, $periodEnd, [AccountType::Revenue]);
        $expenseLines = $this->aggregator->aggregate($tenantId, $periodStart, $periodEnd, [AccountType::Expense]);

        $currency = match (true) {
            $revenueLines !== [] => $revenueLines[0]->totalDebit()->currency(),
            $expenseLines !== [] => $expenseLines[0]->totalDebit()->currency(),
            default => Currency::of('MYR'),
        };

        return new ProfitAndLossStatement($tenantId, $periodStart, $periodEnd, $currency, $revenueLines, $expenseLines);
    }
}
