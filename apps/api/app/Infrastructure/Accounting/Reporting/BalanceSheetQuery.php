<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Reporting\BalanceSheet;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Computes a {@see BalanceSheet} as of a given date (AETS-009 §8, SRS
 * RPT-002) by delegating Asset, Liability, and Equity Accounts to
 * {@see AccountBalanceAggregator}, plus the "unclosed books" Cumulative
 * Net Income line — a {@see ProfitAndLossQuery} computed for
 * `[account inception, asOfDate]`, reusing {@see ProfitAndLossQuery}'s
 * own algebra unchanged rather than re-deriving it.
 *
 * **`self::SINCE_INCEPTION`** stands in for "every Posted Journal that
 * has ever existed" — a date early enough that no real Journal's
 * `financial_date` could ever predate it, so passing it as the lower
 * bound to {@see ProfitAndLossQuery::forPeriod()} is equivalent to no
 * lower bound at all, without requiring that class's own contract to
 * grow a separate "no lower bound" case solely for this one caller.
 */
final class BalanceSheetQuery
{
    private const SINCE_INCEPTION = '0001-01-01';

    public function __construct(
        private readonly AccountBalanceAggregator $aggregator,
        private readonly ProfitAndLossQuery $profitAndLossQuery,
    ) {}

    public function asOf(TenantId $tenantId, \DateTimeImmutable $asOfDate): BalanceSheet
    {
        $assetLines = $this->aggregator->aggregate($tenantId, null, $asOfDate, [AccountType::Asset]);
        $liabilityLines = $this->aggregator->aggregate($tenantId, null, $asOfDate, [AccountType::Liability]);
        $equityLines = $this->aggregator->aggregate($tenantId, null, $asOfDate, [AccountType::Equity]);

        $cumulativeProfitAndLoss = $this->profitAndLossQuery->forPeriod(
            $tenantId,
            new \DateTimeImmutable(self::SINCE_INCEPTION),
            $asOfDate,
        );

        $currency = match (true) {
            $assetLines !== [] => $assetLines[0]->totalDebit()->currency(),
            $liabilityLines !== [] => $liabilityLines[0]->totalDebit()->currency(),
            $equityLines !== [] => $equityLines[0]->totalDebit()->currency(),
            default => Currency::of('MYR'),
        };

        return new BalanceSheet(
            $tenantId,
            $asOfDate,
            $currency,
            $assetLines,
            $liabilityLines,
            $equityLines,
            $cumulativeProfitAndLoss->netIncomeBalance(),
        );
    }
}
