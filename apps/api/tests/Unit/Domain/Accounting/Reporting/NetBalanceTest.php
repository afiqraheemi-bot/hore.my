<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Reporting\NetBalance;
use PHPUnit\Framework\TestCase;

final class NetBalanceTest extends TestCase
{
    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
    }

    public function test_debit_larger_nets_to_debit_direction(): void
    {
        $balance = NetBalance::fromDebitCredit(
            Money::fromDecimalString('150.00', $this->myr),
            Money::fromDecimalString('100.00', $this->myr),
        );

        $this->assertSame('50.00', $balance->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $balance->direction());
        $this->assertFalse($balance->isZero());
    }

    public function test_credit_larger_nets_to_credit_direction(): void
    {
        $balance = NetBalance::fromDebitCredit(
            Money::fromDecimalString('100.00', $this->myr),
            Money::fromDecimalString('150.00', $this->myr),
        );

        $this->assertSame('50.00', $balance->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Credit, $balance->direction());
    }

    /**
     * A Net Balance of exactly zero carries no Direction — this class
     * never derives one from an Account's Normal Balance (AETS-009 §4).
     */
    public function test_equal_debit_and_credit_nets_to_zero_with_no_direction(): void
    {
        $balance = NetBalance::fromDebitCredit(
            Money::fromDecimalString('100.00', $this->myr),
            Money::fromDecimalString('100.00', $this->myr),
        );

        $this->assertSame('0.00', $balance->amount()->toDecimalString());
        $this->assertNull($balance->direction());
        $this->assertTrue($balance->isZero());
    }

    public function test_both_zero_nets_to_zero_with_no_direction(): void
    {
        $zero = Money::fromDecimalString('0.00', $this->myr);
        $balance = NetBalance::fromDebitCredit($zero, $zero);

        $this->assertTrue($balance->isZero());
        $this->assertNull($balance->direction());
    }
}
