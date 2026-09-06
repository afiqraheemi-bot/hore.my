<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Money\Money;

/**
 * One Account's cumulative total Debit Money and total Credit Money
 * over whatever as-of date or Period a report computed it for
 * (AETS-009 §6–§8) — summed exclusively from Posted Journal Lines
 * (AETS-009 §5). Carries no reference to *which* report produced it;
 * {@see TrialBalance}, {@see ProfitAndLossStatement}, and
 * {@see BalanceSheet} each assemble a list of these for their own
 * purpose.
 */
final class AccountBalance
{
    private readonly NetBalance $netBalance;

    public function __construct(
        private readonly AccountId $accountId,
        private readonly AccountType $accountType,
        private readonly Money $totalDebit,
        private readonly Money $totalCredit,
    ) {
        $this->netBalance = NetBalance::fromDebitCredit($totalDebit, $totalCredit);
    }

    public function accountId(): AccountId
    {
        return $this->accountId;
    }

    public function accountType(): AccountType
    {
        return $this->accountType;
    }

    public function totalDebit(): Money
    {
        return $this->totalDebit;
    }

    public function totalCredit(): Money
    {
        return $this->totalCredit;
    }

    public function netBalance(): NetBalance
    {
        return $this->netBalance;
    }
}
