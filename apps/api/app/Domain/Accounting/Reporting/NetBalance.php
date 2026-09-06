<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Money\Exception\CurrencyMismatchException;
use App\Domain\Accounting\Money\Money;

/**
 * The net of a total Debit Money and a total Credit Money (AETS-009
 * §4) — a single non-negative Money magnitude plus the
 * {@see JournalDirection} of whichever side is larger, mirroring
 * AETS-004 §8's own "magnitude-with-direction" resolution for a
 * single Journal Line. Never a signed number.
 *
 * **Zero carries no Direction.** When total Debit exactly equals total
 * Credit, neither side is larger — {@see direction()} returns `null`
 * rather than guessing one. This class never derives a Direction from
 * an Account's Normal Balance: {@see JournalDirection} and
 * `NormalBalance` are, and remain, unrelated types with no conversion
 * between them, exactly as {@see JournalDirection}'s own docblock
 * already establishes.
 */
final class NetBalance
{
    private function __construct(
        private readonly Money $amount,
        private readonly ?JournalDirection $direction,
    ) {}

    /**
     * @throws CurrencyMismatchException if `$totalDebit` and `$totalCredit` do not share a Currency.
     */
    public static function fromDebitCredit(Money $totalDebit, Money $totalCredit): self
    {
        $comparison = $totalDebit->compareTo($totalCredit);

        if ($comparison > 0) {
            return new self($totalDebit->subtract($totalCredit), JournalDirection::Debit);
        }

        if ($comparison < 0) {
            return new self($totalCredit->subtract($totalDebit), JournalDirection::Credit);
        }

        return new self($totalDebit->subtract($totalDebit), null);
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function direction(): ?JournalDirection
    {
        return $this->direction;
    }

    public function isZero(): bool
    {
        return $this->direction === null;
    }
}
