<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Reporting\BalanceSheetQuery;

/**
 * A Profit & Loss / Income Statement for a given Period (AETS-009 §7,
 * SRS RPT-001) — every Revenue-type and Expense-type Account belonging
 * to the Tenant, summed exclusively from Posted Journal Lines with
 * `financialDate` inside `[periodStart, periodEnd]` (AETS-009 §5).
 *
 * **Net Income, never a signed number.** {@see netIncome()} is always
 * a non-negative Money magnitude; {@see isProfit()} carries the
 * distinction a signed number would otherwise encode, mirroring this
 * codebase's established "magnitude-with-direction" discipline
 * (AETS-004 §8) rather than inventing a signed-Money exception to it.
 */
final class ProfitAndLossStatement
{
    /**
     * @param  list<AccountBalance>  $revenueLines
     * @param  list<AccountBalance>  $expenseLines
     */
    public function __construct(
        private readonly TenantId $tenantId,
        private readonly \DateTimeImmutable $periodStart,
        private readonly \DateTimeImmutable $periodEnd,
        private readonly Currency $currency,
        private readonly array $revenueLines,
        private readonly array $expenseLines,
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
     * @return list<AccountBalance>
     */
    public function revenueLines(): array
    {
        return $this->revenueLines;
    }

    /**
     * @return list<AccountBalance>
     */
    public function expenseLines(): array
    {
        return $this->expenseLines;
    }

    /**
     * Total Revenue, as its own group-level {@see NetBalance} magnitude
     * — Revenue's Normal Balance is Credit, so this is ordinarily the
     * Credit-side total, but this method never assumes that direction:
     * it sums every line's own total Debit and total Credit separately
     * first, then nets the two group totals, so an individual Account
     * that happens to net the opposite way (for example, after a large
     * Reversal) is combined correctly rather than double-counted.
     */
    public function totalRevenue(): Money
    {
        return $this->groupNetBalance($this->revenueLines)->amount();
    }

    public function totalExpense(): Money
    {
        return $this->groupNetBalance($this->expenseLines)->amount();
    }

    /**
     * Total Revenue minus total Expense, as a non-negative Money
     * magnitude — see {@see isProfit()} for which side it falls on.
     *
     * **Computed from the four raw Debit/Credit sums directly, never
     * from {@see totalRevenue()}/{@see totalExpense()}'s already-netted
     * magnitudes.** Netting each group first and then comparing the two
     * magnitudes would silently mis-combine them if either group's net
     * happens to fall on its non-Normal-Balance side (for example, a
     * Revenue group net Debit after an unusually large Reversal) —
     * economically, `(revenueCredit - revenueDebit) - (expenseDebit -
     * expenseCredit)` is always the correct Net Income regardless of
     * which side either group nets to, so this method accumulates the
     * four sums itself via {@see netIncomeBalance()}.
     */
    public function netIncome(): Money
    {
        return $this->netIncomeBalance()->amount();
    }

    /**
     * `true` when the accumulated Net Income (§{@see netIncome()})
     * falls on the Credit side (a profit, or exactly break-even, per
     * {@see NetBalance::isZero()}); `false` when it falls on the Debit
     * side (a loss).
     */
    public function isProfit(): bool
    {
        $direction = $this->netIncomeBalance()->direction();

        return $direction === null || $direction === JournalDirection::Credit;
    }

    /**
     * The full {@see NetBalance} behind {@see netIncome()}/{@see isProfit()}
     * — exposed directly so a caller (for example,
     * {@see BalanceSheetQuery},
     * for the "unclosed books" Cumulative Net Income line, AETS-009 §8)
     * can reuse this exact computation without re-deriving it.
     */
    public function netIncomeBalance(): NetBalance
    {
        $incomeReducingTotal = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);
        $incomeIncreasingTotal = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);

        foreach ($this->revenueLines as $line) {
            $incomeReducingTotal = $incomeReducingTotal->add($line->totalDebit());
            $incomeIncreasingTotal = $incomeIncreasingTotal->add($line->totalCredit());
        }

        foreach ($this->expenseLines as $line) {
            $incomeReducingTotal = $incomeReducingTotal->add($line->totalDebit());
            $incomeIncreasingTotal = $incomeIncreasingTotal->add($line->totalCredit());
        }

        return NetBalance::fromDebitCredit($incomeReducingTotal, $incomeIncreasingTotal);
    }

    /**
     * @param  list<AccountBalance>  $lines
     */
    private function groupNetBalance(array $lines): NetBalance
    {
        $totalDebit = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);
        $totalCredit = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);

        foreach ($lines as $line) {
            $totalDebit = $totalDebit->add($line->totalDebit());
            $totalCredit = $totalCredit->add($line->totalCredit());
        }

        return NetBalance::fromDebitCredit($totalDebit, $totalCredit);
    }
}
