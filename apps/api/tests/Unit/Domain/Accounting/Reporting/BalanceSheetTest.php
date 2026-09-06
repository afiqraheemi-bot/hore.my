<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\BalanceSheet;
use App\Domain\Accounting\Reporting\NetBalance;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see BalanceSheet} (AETS-009 §8, SRS RPT-002) at the pure
 * construction/computation level — no persistence, no query.
 */
final class BalanceSheetTest extends TestCase
{
    private Currency $myr;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
        $this->tenantId = TenantId::of('tenant-0001');
    }

    /**
     * `RPT-008`, the golden Balance Sheet example from AETS-009 §14: an
     * Expense (Debit Office Supplies RM50.00, Credit Cash RM50.00) and
     * an Income (Debit Cash RM200.00, Credit Consulting Revenue
     * RM200.00) — Cash nets Debit RM150.00; Cumulative Net Income is
     * RM150.00 profit; Assets (RM150.00) exactly equal Liabilities
     * (RM0.00) plus Equity (RM150.00).
     */
    public function test_golden_example_balances_with_cumulative_net_income(): void
    {
        $balanceSheet = new BalanceSheet(
            $this->tenantId,
            new \DateTimeImmutable('2026-08-31'),
            $this->myr,
            [$this->balance('account-cash', AccountType::Asset, '200.00', '50.00')],
            [],
            [],
            NetBalance::fromDebitCredit(
                Money::fromDecimalString('50.00', $this->myr),
                Money::fromDecimalString('200.00', $this->myr),
            ),
        );

        $this->assertSame('150.00', $balanceSheet->totalAssets()->toDecimalString());
        $this->assertSame('150.00', $balanceSheet->totalLiabilitiesAndEquity()->toDecimalString());
        $this->assertTrue($balanceSheet->isBalanced());
    }

    public function test_balanced_with_real_equity_and_liability_accounts(): void
    {
        // Assets: Cash Debit 5000.00 net.
        // Liabilities: Loan Credit 2000.00 net.
        // Equity: Owner Capital Credit 3150.00 net.
        // Cumulative Net Income: 150.00 debit -> wait, kept as zero here
        // for a clean, no-operations-yet scenario: pure capital funding.
        $balanceSheet = new BalanceSheet(
            $this->tenantId,
            new \DateTimeImmutable('2026-08-31'),
            $this->myr,
            [$this->balance('account-cash', AccountType::Asset, '5000.00', '0.00')],
            [$this->balance('account-loan', AccountType::Liability, '0.00', '2000.00')],
            [$this->balance('account-owner-capital', AccountType::Equity, '0.00', '3000.00')],
            NetBalance::fromDebitCredit(
                Money::fromDecimalString('0.00', $this->myr),
                Money::fromDecimalString('0.00', $this->myr),
            ),
        );

        $this->assertSame('5000.00', $balanceSheet->totalAssets()->toDecimalString());
        $this->assertSame('5000.00', $balanceSheet->totalLiabilitiesAndEquity()->toDecimalString());
        $this->assertTrue($balanceSheet->isBalanced());
    }

    public function test_a_deliberately_unbalanced_sheet_reports_as_unbalanced(): void
    {
        $balanceSheet = new BalanceSheet(
            $this->tenantId,
            new \DateTimeImmutable('2026-08-31'),
            $this->myr,
            [$this->balance('account-cash', AccountType::Asset, '500.00', '0.00')],
            [],
            [$this->balance('account-owner-capital', AccountType::Equity, '0.00', '300.00')],
            NetBalance::fromDebitCredit(
                Money::fromDecimalString('0.00', $this->myr),
                Money::fromDecimalString('0.00', $this->myr),
            ),
        );

        $this->assertFalse($balanceSheet->isBalanced());
    }

    /**
     * The Cumulative Net Income line, when it is a loss (Debit
     * direction), is folded into the Debit-side pool rather than
     * subtracted from Equity's own Credit total — the direction-
     * agnostic technique {@see BalanceSheet::isBalanced()}'s own
     * docblock describes.
     */
    public function test_a_cumulative_net_loss_still_balances_correctly(): void
    {
        // Assets: Cash Debit 4850.00 (5000 capital - 150 net loss paid out).
        // Equity: Owner Capital Credit 5000.00.
        // Cumulative Net Income: Debit (loss) 150.00.
        // Assets (4850) = Equity (5000) - Loss (150) = 4850. Balanced.
        $balanceSheet = new BalanceSheet(
            $this->tenantId,
            new \DateTimeImmutable('2026-08-31'),
            $this->myr,
            [$this->balance('account-cash', AccountType::Asset, '4850.00', '0.00')],
            [],
            [$this->balance('account-owner-capital', AccountType::Equity, '0.00', '5000.00')],
            NetBalance::fromDebitCredit(
                Money::fromDecimalString('150.00', $this->myr),
                Money::fromDecimalString('0.00', $this->myr),
            ),
        );

        $this->assertTrue($balanceSheet->isBalanced());
    }

    public function test_zero_cumulative_net_income_does_not_disturb_an_otherwise_balanced_sheet(): void
    {
        $balanceSheet = new BalanceSheet(
            $this->tenantId,
            new \DateTimeImmutable('2026-08-31'),
            $this->myr,
            [$this->balance('account-cash', AccountType::Asset, '1000.00', '0.00')],
            [],
            [$this->balance('account-owner-capital', AccountType::Equity, '0.00', '1000.00')],
            NetBalance::fromDebitCredit(
                Money::fromDecimalString('0.00', $this->myr),
                Money::fromDecimalString('0.00', $this->myr),
            ),
        );

        $this->assertTrue($balanceSheet->isBalanced());
        $this->assertTrue($balanceSheet->cumulativeNetIncome()->isZero());
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
