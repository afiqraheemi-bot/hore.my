<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\SourceReference;
use App\Domain\Accounting\Reporting\GeneralLedgerEntry;
use App\Domain\Accounting\Reporting\GeneralLedgerRunningBalance;
use App\Domain\Accounting\Reporting\NetBalance;
use PHPUnit\Framework\TestCase;

/**
 * AETS-009 §21, `RPT-020` (added v1.9.0) — the one genuinely new
 * derived value the extended report PDFs introduce. Hand-verified
 * against the same mixed-direction sequence this class was built and
 * live-verified against (real posted Expense/Income activity,
 * downloaded PDF, eyeballed row by row):
 *
 * | opening | entry | running balance |
 * |---|---|---|
 * | 0.00 |  | 0.00 |
 * |  | Credit 45.90 | 45.90 Cr |
 * |  | Debit 500.00 | 454.10 Dr |
 * |  | Credit 12.00 | 442.10 Dr |
 * |  | Debit 80.00 | 522.10 Dr |
 */
final class GeneralLedgerRunningBalanceTest extends TestCase
{
    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
    }

    public function test_running_balance_accumulates_mixed_directions_correctly(): void
    {
        $opening = NetBalance::fromDebitCredit(
            Money::fromDecimalString('0.00', $this->myr),
            Money::fromDecimalString('0.00', $this->myr),
        );

        $entries = [
            $this->entry('45.90', JournalDirection::Credit),
            $this->entry('500.00', JournalDirection::Debit),
            $this->entry('12.00', JournalDirection::Credit),
            $this->entry('80.00', JournalDirection::Debit),
        ];

        $balances = GeneralLedgerRunningBalance::forEntries($opening, $entries);

        $this->assertCount(4, $balances);

        $this->assertSame('45.90', $balances[0]->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Credit, $balances[0]->direction());

        $this->assertSame('454.10', $balances[1]->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $balances[1]->direction());

        $this->assertSame('442.10', $balances[2]->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $balances[2]->direction());

        $this->assertSame('522.10', $balances[3]->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $balances[3]->direction());
    }

    /**
     * Two entries in the same direction as a non-zero opening balance
     * simply accumulate — no netting branch is exercised at all.
     */
    public function test_running_balance_with_non_zero_opening_and_same_direction_entries_accumulates(): void
    {
        $opening = NetBalance::fromDebitCredit(
            Money::fromDecimalString('100.00', $this->myr),
            Money::fromDecimalString('0.00', $this->myr),
        );

        $balances = GeneralLedgerRunningBalance::forEntries($opening, [
            $this->entry('50.00', JournalDirection::Debit),
            $this->entry('25.00', JournalDirection::Debit),
        ]);

        $this->assertSame('150.00', $balances[0]->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $balances[0]->direction());

        $this->assertSame('175.00', $balances[1]->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $balances[1]->direction());
    }

    /**
     * An entry exactly equal to the running balance, in the opposite
     * direction, nets to zero with no Direction — the same "no
     * fabricated Direction for zero" rule `NetBalanceTest` already
     * proves for `fromDebitCredit()` itself.
     */
    public function test_an_entry_exactly_offsetting_the_running_balance_nets_to_zero_with_no_direction(): void
    {
        $opening = NetBalance::fromDebitCredit(
            Money::fromDecimalString('100.00', $this->myr),
            Money::fromDecimalString('0.00', $this->myr),
        );

        $balances = GeneralLedgerRunningBalance::forEntries($opening, [
            $this->entry('100.00', JournalDirection::Credit),
        ]);

        $this->assertSame('0.00', $balances[0]->amount()->toDecimalString());
        $this->assertNull($balances[0]->direction());
        $this->assertTrue($balances[0]->isZero());
    }

    private function entry(string $amount, JournalDirection $direction): GeneralLedgerEntry
    {
        return new GeneralLedgerEntry(
            JournalId::of('journal-0001'),
            new \DateTimeImmutable('2026-08-10'),
            new \DateTimeImmutable('2026-08-10 09:00:00'),
            Money::fromDecimalString($amount, $this->myr),
            $direction,
            SourceReference::of('expense:expense-0001'),
            [],
        );
    }
}
