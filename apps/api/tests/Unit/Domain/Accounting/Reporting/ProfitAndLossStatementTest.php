<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\ProfitAndLossStatement;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see ProfitAndLossStatement} (AETS-009 §7, SRS RPT-001) at
 * the pure construction/computation level — no persistence, no query.
 */
final class ProfitAndLossStatementTest extends TestCase
{
    private Currency $myr;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
        $this->tenantId = TenantId::of('tenant-0001');
    }

    public function test_revenue_exceeding_expense_is_a_profit(): void
    {
        $statement = $this->makeStatement(
            revenueLines: [$this->balance('account-consulting-revenue', AccountType::Revenue, '0.00', '200.00')],
            expenseLines: [$this->balance('account-office-supplies', AccountType::Expense, '50.00', '0.00')],
        );

        $this->assertSame('200.00', $statement->totalRevenue()->toDecimalString());
        $this->assertSame('50.00', $statement->totalExpense()->toDecimalString());
        $this->assertSame('150.00', $statement->netIncome()->toDecimalString());
        $this->assertTrue($statement->isProfit());
    }

    public function test_expense_exceeding_revenue_is_a_loss(): void
    {
        $statement = $this->makeStatement(
            revenueLines: [$this->balance('account-consulting-revenue', AccountType::Revenue, '0.00', '50.00')],
            expenseLines: [$this->balance('account-office-supplies', AccountType::Expense, '200.00', '0.00')],
        );

        $this->assertSame('150.00', $statement->netIncome()->toDecimalString());
        $this->assertFalse($statement->isProfit());
    }

    public function test_exact_break_even_is_reported_as_profit(): void
    {
        $statement = $this->makeStatement(
            revenueLines: [$this->balance('account-consulting-revenue', AccountType::Revenue, '0.00', '100.00')],
            expenseLines: [$this->balance('account-office-supplies', AccountType::Expense, '100.00', '0.00')],
        );

        $this->assertSame('0.00', $statement->netIncome()->toDecimalString());
        $this->assertTrue($statement->isProfit());
    }

    /**
     * A Revenue Account that nets Debit-direction (for example, after
     * an unusually large Reversal) still combines correctly into Net
     * Income — this is exactly the scenario
     * {@see ProfitAndLossStatement::netIncomeBalance()}'s own docblock
     * names as the reason it never compares two already-netted
     * magnitudes.
     */
    public function test_revenue_account_netting_debit_direction_still_computes_correctly(): void
    {
        // Revenue account net Debit RM30.00 (a reversal exceeded the
        // original revenue); Expense account net Debit RM50.00 (normal).
        // True Net Income = (0 - 30) - (50 - 0) = -80 => a loss of RM80.00.
        $statement = $this->makeStatement(
            revenueLines: [$this->balance('account-consulting-revenue', AccountType::Revenue, '30.00', '0.00')],
            expenseLines: [$this->balance('account-office-supplies', AccountType::Expense, '50.00', '0.00')],
        );

        $this->assertSame('80.00', $statement->netIncome()->toDecimalString());
        $this->assertFalse($statement->isProfit());
    }

    public function test_multiple_revenue_and_expense_accounts_aggregate_correctly(): void
    {
        $statement = $this->makeStatement(
            revenueLines: [
                $this->balance('account-consulting-revenue', AccountType::Revenue, '0.00', '150.00'),
                $this->balance('account-product-revenue', AccountType::Revenue, '0.00', '50.00'),
            ],
            expenseLines: [
                $this->balance('account-office-supplies', AccountType::Expense, '30.00', '0.00'),
                $this->balance('account-travel', AccountType::Expense, '20.00', '0.00'),
            ],
        );

        $this->assertSame('200.00', $statement->totalRevenue()->toDecimalString());
        $this->assertSame('50.00', $statement->totalExpense()->toDecimalString());
        $this->assertSame('150.00', $statement->netIncome()->toDecimalString());
    }

    public function test_no_activity_is_break_even(): void
    {
        $statement = $this->makeStatement(revenueLines: [], expenseLines: []);

        $this->assertSame('0.00', $statement->netIncome()->toDecimalString());
        $this->assertTrue($statement->isProfit());
    }

    /**
     * @param  list<AccountBalance>  $revenueLines
     * @param  list<AccountBalance>  $expenseLines
     */
    private function makeStatement(array $revenueLines, array $expenseLines): ProfitAndLossStatement
    {
        return new ProfitAndLossStatement(
            $this->tenantId,
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
            $this->myr,
            $revenueLines,
            $expenseLines,
        );
    }

    private function balance(string $accountId, AccountType $type, string $debit, string $credit): AccountBalance
    {
        return new AccountBalance(
            AccountId::of($accountId),
            $type,
            Money::fromDecimalString($debit, $this->myr),
            Money::fromDecimalString($credit, $this->myr),
        );
    }
}
