<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Reporting\CashFlowLine;
use App\Domain\Accounting\Reporting\CashFlowStatement;
use App\Domain\Accounting\Reporting\NetBalance;
use App\Domain\Accounting\Reporting\ProfitAndLossStatement;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Infrastructure\Accounting\Reporting\CashFlowStatementQueryIntegrationTest;

/**
 * Covers {@see CashFlowStatement} (AETS-009 §22, SRS RPT-003) at the
 * pure construction/computation level — no persistence, no query;
 * {@see CashFlowStatementQueryIntegrationTest}
 * covers the real-PostgreSQL derivation itself.
 */
final class CashFlowStatementTest extends TestCase
{
    private Currency $myr;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
        $this->tenantId = TenantId::of('tenant-0001');
    }

    public function test_a_single_operating_inflow_line_produces_a_matching_total(): void
    {
        $statement = $this->makeStatement(
            operatingLines: [$this->line('account-sales-revenue', '500.00', 'Debit')],
        );

        $this->assertSame('500.00', $statement->operatingTotal()->amount()->toDecimalString());
        $this->assertNotNull($statement->operatingTotal()->direction());
        $this->assertSame('Debit', $statement->operatingTotal()->direction()->name);
    }

    public function test_operating_lines_with_mixed_directions_net_correctly(): void
    {
        // Sales Revenue +500.00 (inflow), Rent Expense -100.00 (outflow).
        $statement = $this->makeStatement(
            operatingLines: [
                $this->line('account-sales-revenue', '500.00', 'Debit'),
                $this->line('account-rent-expense', '100.00', 'Credit'),
            ],
        );

        $this->assertSame('400.00', $statement->operatingTotal()->amount()->toDecimalString());
        $this->assertSame('Debit', $statement->operatingTotal()->direction()->name);
    }

    /**
     * Net Change in Cash sums all three activities' own raw
     * contributions directly — never the three already-netted
     * magnitudes — exactly like
     * {@see ProfitAndLossStatement::netIncome()}'s
     * own established reasoning.
     */
    public function test_net_change_in_cash_sums_all_three_activities(): void
    {
        $statement = $this->makeStatement(
            operatingLines: [$this->line('account-sales-revenue', '400.00', 'Debit')],
            investingLines: [],
            financingLines: [
                $this->line('account-loans-payable', '800.00', 'Debit'),
                $this->line('account-owners-drawings', '50.00', 'Credit'),
            ],
        );

        $this->assertSame('1150.00', $statement->netChangeInCash()->amount()->toDecimalString());
        $this->assertSame('Debit', $statement->netChangeInCash()->direction()->name);
    }

    public function test_an_empty_statement_is_break_even_with_no_direction(): void
    {
        $statement = $this->makeStatement();

        $this->assertTrue($statement->operatingTotal()->isZero());
        $this->assertTrue($statement->investingTotal()->isZero());
        $this->assertTrue($statement->financingTotal()->isZero());
        $this->assertTrue($statement->netChangeInCash()->isZero());
    }

    public function test_cash_at_period_start_and_end_are_exposed_unchanged(): void
    {
        $start = NetBalance::fromDebitCredit(
            Money::fromDecimalString('0.00', $this->myr),
            Money::fromDecimalString('0.00', $this->myr),
        );
        $end = NetBalance::fromDebitCredit(
            Money::fromDecimalString('1450.00', $this->myr),
            Money::fromDecimalString('0.00', $this->myr),
        );

        $statement = new CashFlowStatement(
            $this->tenantId,
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-30'),
            $this->myr,
            [],
            [],
            [],
            $start,
            $end,
        );

        $this->assertTrue($statement->cashAtPeriodStart()->isZero());
        $this->assertSame('1450.00', $statement->cashAtPeriodEnd()->amount()->toDecimalString());
    }

    /**
     * @param  list<CashFlowLine>  $operatingLines
     * @param  list<CashFlowLine>  $investingLines
     * @param  list<CashFlowLine>  $financingLines
     */
    private function makeStatement(array $operatingLines = [], array $investingLines = [], array $financingLines = []): CashFlowStatement
    {
        $zero = NetBalance::fromDebitCredit(
            Money::fromDecimalString('0.00', $this->myr),
            Money::fromDecimalString('0.00', $this->myr),
        );

        return new CashFlowStatement(
            $this->tenantId,
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-30'),
            $this->myr,
            $operatingLines,
            $investingLines,
            $financingLines,
            $zero,
            $zero,
        );
    }

    private function line(string $accountId, string $amount, string $direction): CashFlowLine
    {
        $money = Money::fromDecimalString($amount, $this->myr);
        $zero = Money::fromDecimalString('0.00', $this->myr);

        $netBalance = $direction === 'Debit'
            ? NetBalance::fromDebitCredit($money, $zero)
            : NetBalance::fromDebitCredit($zero, $money);

        return new CashFlowLine(AccountId::of($accountId), $netBalance);
    }
}
