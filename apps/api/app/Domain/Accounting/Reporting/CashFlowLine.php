<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;

/**
 * One counterparty Account's net cash impact within a single
 * {@see CashFlowActivity} section, for a given Period (AETS-009 §22)
 * — the cash-equivalent side of every Posted Journal that also
 * touched this counterparty Account, netted to a single
 * {@see NetBalance} (Debit direction = net cash inflow, Credit =
 * net cash outflow, mirroring Cash's own Asset Normal Balance).
 *
 * `accountId` names the *counterparty* — the Revenue, Expense,
 * Liability, Equity, or non-cash Asset Account on the other side of
 * the cash movement — never the cash-equivalent Account itself,
 * which never appears as a line in its own statement.
 */
final class CashFlowLine
{
    public function __construct(
        private readonly AccountId $accountId,
        private readonly NetBalance $netCashFlow,
    ) {}

    public function accountId(): AccountId
    {
        return $this->accountId;
    }

    public function netCashFlow(): NetBalance
    {
        return $this->netCashFlow;
    }
}
