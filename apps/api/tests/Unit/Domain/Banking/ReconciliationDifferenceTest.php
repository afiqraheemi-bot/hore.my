<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\ReconciliationDifference;
use App\Domain\Banking\ReconciliationDifferenceSign;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Accounting\Reporting\NetBalanceTest;

/**
 * Covers {@see ReconciliationDifference} (M18) — the direction-agnostic
 * magnitude/sign resolution, mirroring
 * {@see NetBalanceTest}'s own
 * coverage pattern for the identical structural convention.
 */
final class ReconciliationDifferenceTest extends TestCase
{
    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->myr = Currency::of('MYR');
    }

    public function test_equal_balances_are_zero_with_no_sign(): void
    {
        $difference = ReconciliationDifference::compute(
            Money::fromDecimalString('1000.00', $this->myr),
            Money::fromDecimalString('1000.00', $this->myr),
        );

        $this->assertTrue($difference->isZero());
        $this->assertNull($difference->sign());
        $this->assertSame('0.00', $difference->amount()->toDecimalString());
    }

    public function test_stated_higher_than_implied_is_over(): void
    {
        $difference = ReconciliationDifference::compute(
            Money::fromDecimalString('1050.00', $this->myr),
            Money::fromDecimalString('1000.00', $this->myr),
        );

        $this->assertFalse($difference->isZero());
        $this->assertSame(ReconciliationDifferenceSign::Over, $difference->sign());
        $this->assertSame('50.00', $difference->amount()->toDecimalString());
    }

    public function test_stated_lower_than_implied_is_short(): void
    {
        $difference = ReconciliationDifference::compute(
            Money::fromDecimalString('950.00', $this->myr),
            Money::fromDecimalString('1000.00', $this->myr),
        );

        $this->assertFalse($difference->isZero());
        $this->assertSame(ReconciliationDifferenceSign::Short, $difference->sign());
        $this->assertSame('50.00', $difference->amount()->toDecimalString());
    }
}
