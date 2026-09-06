<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;

/**
 * The General Ledger drill-down for a single Account over a given
 * Period (AETS-009 §9, SRS RPT-005) — every {@see GeneralLedgerEntry}
 * referencing that Account with `financialDate` inside the Period, in
 * `financialDate` order (ties broken by `postedAt`), together with the
 * Account's opening and closing {@see NetBalance}.
 *
 * **Opening/closing balances are a convenience, not authoritative.**
 * Both remain fully re-derivable from the same underlying Journal Lines
 * (AETS-009 §5, §9) — this class does not introduce a stored balance
 * a future report could drift from.
 */
final class GeneralLedgerAccountActivity
{
    /**
     * @param  list<GeneralLedgerEntry>  $entries
     */
    public function __construct(
        private readonly AccountId $accountId,
        private readonly \DateTimeImmutable $periodStart,
        private readonly \DateTimeImmutable $periodEnd,
        private readonly NetBalance $openingBalance,
        private readonly array $entries,
        private readonly NetBalance $closingBalance,
    ) {}

    public function accountId(): AccountId
    {
        return $this->accountId;
    }

    public function periodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function periodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function openingBalance(): NetBalance
    {
        return $this->openingBalance;
    }

    /**
     * @return list<GeneralLedgerEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function closingBalance(): NetBalance
    {
        return $this->closingBalance;
    }
}
