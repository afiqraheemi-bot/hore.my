<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Accounting\Reporting\CashFlowStatementQuery;

/**
 * A Cash Flow Statement for a given Period (AETS-009 §22, SRS RPT-003)
 * — every Posted Journal that touches a cash-equivalent Account
 * (§22's own definition), grouped into Operating/Investing/Financing
 * by its counterparty Account's Type, plus the Tenant's own total
 * cash-equivalent balance at the start and end of the Period.
 *
 * **Net Change in Cash, never a signed number.** {@see netChangeInCash()}
 * is always a non-negative Money magnitude with a direction, mirroring
 * every other "magnitude-with-direction" total this codebase already
 * establishes (AETS-004 §8; {@see ProfitAndLossStatement::netIncome()}).
 *
 * **Tie-out is structural, not merely asserted.** {@see netChangeInCash()}
 * is computed directly from the three activity totals' own raw
 * Debit/Credit accumulation — {@see CashFlowStatementQuery}
 * independently computes {@see cashAtPeriodStart()} and
 * {@see cashAtPeriodEnd()} from the Tenant's own cash-equivalent
 * Account balances, and `RPT-018` requires the two to always agree:
 * `cashAtPeriodEnd - cashAtPeriodStart === netChangeInCash` for any
 * Tenant and Period.
 */
final class CashFlowStatement
{
    /**
     * @param  list<CashFlowLine>  $operatingLines
     * @param  list<CashFlowLine>  $investingLines
     * @param  list<CashFlowLine>  $financingLines
     */
    public function __construct(
        private readonly TenantId $tenantId,
        private readonly \DateTimeImmutable $periodStart,
        private readonly \DateTimeImmutable $periodEnd,
        private readonly Currency $currency,
        private readonly array $operatingLines,
        private readonly array $investingLines,
        private readonly array $financingLines,
        private readonly NetBalance $cashAtPeriodStart,
        private readonly NetBalance $cashAtPeriodEnd,
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
     * @return list<CashFlowLine>
     */
    public function operatingLines(): array
    {
        return $this->operatingLines;
    }

    /**
     * @return list<CashFlowLine>
     */
    public function investingLines(): array
    {
        return $this->investingLines;
    }

    /**
     * @return list<CashFlowLine>
     */
    public function financingLines(): array
    {
        return $this->financingLines;
    }

    public function operatingTotal(): NetBalance
    {
        return $this->groupNetBalance($this->operatingLines);
    }

    public function investingTotal(): NetBalance
    {
        return $this->groupNetBalance($this->investingLines);
    }

    public function financingTotal(): NetBalance
    {
        return $this->groupNetBalance($this->financingLines);
    }

    public function cashAtPeriodStart(): NetBalance
    {
        return $this->cashAtPeriodStart;
    }

    public function cashAtPeriodEnd(): NetBalance
    {
        return $this->cashAtPeriodEnd;
    }

    /**
     * The sum of all three activity totals, computed from their own
     * raw Debit/Credit contributions directly — never from the three
     * already-netted {@see operatingTotal()}/{@see investingTotal()}/
     * {@see financingTotal()} magnitudes, for the identical reason
     * {@see ProfitAndLossStatement::netIncome()}'s own docblock gives:
     * summing already-netted magnitudes would silently mis-combine
     * them if one section's net happens to fall on the opposite side.
     */
    public function netChangeInCash(): NetBalance
    {
        return $this->groupNetBalance([
            ...$this->operatingLines,
            ...$this->investingLines,
            ...$this->financingLines,
        ]);
    }

    /**
     * @param  list<CashFlowLine>  $lines
     */
    private function groupNetBalance(array $lines): NetBalance
    {
        $totalDebit = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);
        $totalCredit = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);

        foreach ($lines as $line) {
            $balance = $line->netCashFlow();

            if ($balance->direction() === JournalDirection::Debit) {
                $totalDebit = $totalDebit->add($balance->amount());
            } elseif ($balance->direction() === JournalDirection::Credit) {
                $totalCredit = $totalCredit->add($balance->amount());
            }
        }

        return NetBalance::fromDebitCredit($totalDebit, $totalCredit);
    }
}
