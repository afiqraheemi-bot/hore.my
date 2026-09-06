<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A Balance Sheet / Statement of Financial Position as of a given date
 * (AETS-009 §8, SRS RPT-002) — every Asset-type, Liability-type, and
 * Equity-type Account belonging to the Tenant, at the same
 * cumulative-since-inception {@see AccountBalance} a {@see TrialBalance}
 * as of the same date already computes for each, plus one additional,
 * computed line: {@see cumulativeNetIncome()}.
 *
 * **The "unclosed books" Cumulative Net Income line (AETS-009 §8).** No
 * period-closing mechanism exists in this codebase yet — Revenue and
 * Expense Accounts are never zeroed by a closing entry, so for
 * `Assets = Liabilities + Equity` to hold at any as-of date, this class
 * requires the caller to supply Net Income accumulated since account
 * inception up to this same as-of date (a {@see ProfitAndLossStatement}
 * computed for `[inception, asOfDate]`, not merely the most recent
 * period) as an explicit {@see NetBalance} — this class does not compute
 * it itself, since doing so would require its own Journal Line query,
 * duplicating {@see ProfitAndLossStatement}'s own responsibility.
 */
final class BalanceSheet
{
    /**
     * @param  list<AccountBalance>  $assetLines
     * @param  list<AccountBalance>  $liabilityLines
     * @param  list<AccountBalance>  $equityLines
     */
    public function __construct(
        private readonly TenantId $tenantId,
        private readonly \DateTimeImmutable $asOfDate,
        private readonly Currency $currency,
        private readonly array $assetLines,
        private readonly array $liabilityLines,
        private readonly array $equityLines,
        private readonly NetBalance $cumulativeNetIncome,
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
    public function assetLines(): array
    {
        return $this->assetLines;
    }

    /**
     * @return list<AccountBalance>
     */
    public function liabilityLines(): array
    {
        return $this->liabilityLines;
    }

    /**
     * @return list<AccountBalance>
     */
    public function equityLines(): array
    {
        return $this->equityLines;
    }

    /**
     * Net Income accumulated since account inception, up to
     * {@see asOfDate()} — the "unclosed books" line (see this class's
     * own docblock). `direction() === Credit` is retained
     * earnings/profit; `Debit` is an accumulated loss.
     */
    public function cumulativeNetIncome(): NetBalance
    {
        return $this->cumulativeNetIncome;
    }

    public function totalAssets(): Money
    {
        [$debit, $credit] = $this->sumDebitCredit($this->assetLines);

        return NetBalance::fromDebitCredit($debit, $credit)->amount();
    }

    /**
     * Total Liabilities plus total Equity, including the Cumulative Net
     * Income line — see {@see isBalanced()} for the exact-equality
     * check against {@see totalAssets()} this figure must satisfy.
     */
    public function totalLiabilitiesAndEquity(): Money
    {
        [$debit, $credit] = $this->sumDebitCredit([...$this->liabilityLines, ...$this->equityLines]);
        [$debit, $credit] = $this->foldIntoDebitCredit($debit, $credit, $this->cumulativeNetIncome);

        return NetBalance::fromDebitCredit($debit, $credit)->amount();
    }

    /**
     * `Assets = Liabilities + Equity` (`RPT-008`), proven directly by
     * comparing the total Debit pool (every Asset/Liability/Equity
     * line's own Debit total, plus the Cumulative Net Income line's
     * Debit-side contribution if it is a loss) against the total Credit
     * pool (the same lines' Credit totals, plus the Cumulative Net
     * Income line's Credit-side contribution if it is a profit) —
     * exactly the same direction-agnostic technique
     * {@see TrialBalance::isBalanced()} already uses, restricted to
     * three Account Types plus one computed line, never a comparison of
     * two already-netted magnitudes that could silently disagree if
     * either side's net happens to fall the opposite way.
     */
    public function isBalanced(): bool
    {
        [$assetDebit, $assetCredit] = $this->sumDebitCredit($this->assetLines);
        [$otherDebit, $otherCredit] = $this->sumDebitCredit([...$this->liabilityLines, ...$this->equityLines]);
        [$otherDebit, $otherCredit] = $this->foldIntoDebitCredit($otherDebit, $otherCredit, $this->cumulativeNetIncome);

        $totalDebit = $assetDebit->add($otherDebit);
        $totalCredit = $assetCredit->add($otherCredit);

        return $totalDebit->equals($totalCredit);
    }

    /**
     * @param  list<AccountBalance>  $lines
     * @return array{0: Money, 1: Money}
     */
    private function sumDebitCredit(array $lines): array
    {
        $totalDebit = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);
        $totalCredit = Money::fromMinorUnits(MinorUnits::of('0'), $this->currency);

        foreach ($lines as $line) {
            $totalDebit = $totalDebit->add($line->totalDebit());
            $totalCredit = $totalCredit->add($line->totalCredit());
        }

        return [$totalDebit, $totalCredit];
    }

    /**
     * @return array{0: Money, 1: Money}
     */
    private function foldIntoDebitCredit(Money $debit, Money $credit, NetBalance $balance): array
    {
        if ($balance->isZero()) {
            return [$debit, $credit];
        }

        return $balance->direction() === JournalDirection::Debit
            ? [$debit->add($balance->amount()), $credit]
            : [$debit, $credit->add($balance->amount())];
    }
}
