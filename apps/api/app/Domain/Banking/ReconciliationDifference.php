<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Exception\CurrencyMismatchException;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Reporting\NetBalance;

/**
 * The difference between a Reconciliation's own stated closing balance
 * and the closing balance its imported BankTransactions imply (M18,
 * SRS BNK-006: "...dan perbezaan hendaklah dijejak" — and the
 * difference shall be tracked; §10.5: "Rekonsiliasi selesai mempunyai
 * perbezaan tidak dijelaskan sifar" — a completed Reconciliation has
 * zero unexplained difference).
 *
 * Mirrors {@see NetBalance}'s own
 * structural pattern exactly (a single non-negative Money magnitude
 * plus an optional two-value sign, `null` when zero) — the identical
 * "magnitude-with-direction, never a signed number" resolution, reused
 * here for a Banking-native concept that is not itself a ledger
 * Debit/Credit (see {@see ReconciliationDifferenceSign}'s own
 * docblock).
 */
final class ReconciliationDifference
{
    private function __construct(
        private readonly Money $amount,
        private readonly ?ReconciliationDifferenceSign $sign,
    ) {}

    /**
     * @throws CurrencyMismatchException if the two Money values do not
     *                                   share a Currency.
     */
    public static function compute(Money $statedClosingBalance, Money $impliedClosingBalance): self
    {
        $comparison = $statedClosingBalance->compareTo($impliedClosingBalance);

        if ($comparison > 0) {
            return new self($statedClosingBalance->subtract($impliedClosingBalance), ReconciliationDifferenceSign::Over);
        }

        if ($comparison < 0) {
            return new self($impliedClosingBalance->subtract($statedClosingBalance), ReconciliationDifferenceSign::Short);
        }

        return new self($statedClosingBalance->subtract($statedClosingBalance), null);
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function sign(): ?ReconciliationDifferenceSign
    {
        return $this->sign;
    }

    public function isZero(): bool
    {
        return $this->sign === null;
    }
}
