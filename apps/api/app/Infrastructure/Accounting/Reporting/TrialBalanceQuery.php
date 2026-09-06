<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Reporting;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Reporting\TrialBalance;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * Computes a {@see TrialBalance} as of a given date (AETS-009 §6, SRS
 * RPT-004) by delegating the full Chart of Accounts to
 * {@see AccountBalanceAggregator} with no Account Type restriction and
 * no lower date bound — cumulative since each Account's own inception,
 * exactly as a Trial Balance requires.
 */
final class TrialBalanceQuery
{
    public function __construct(
        private readonly AccountBalanceAggregator $aggregator,
    ) {}

    public function asOf(TenantId $tenantId, \DateTimeImmutable $asOfDate): TrialBalance
    {
        $lines = $this->aggregator->aggregate($tenantId, null, $asOfDate);

        $currency = $lines === [] ? Currency::of('MYR') : $lines[0]->totalDebit()->currency();

        return new TrialBalance($tenantId, $asOfDate, $currency, $lines);
    }
}
