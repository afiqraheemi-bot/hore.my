<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Accounting\Reporting\TrialBalance;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see TrialBalance} at the pure construction/computation level
 * (AETS-009 §6, `RPT-007`) — no persistence, no query.
 */
final class TrialBalanceTest extends TestCase
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
     * `RPT-007`: a balanced set of lines is proven to balance — the
     * golden invariant every Trial Balance must satisfy.
     */
    public function test_balanced_lines_report_as_balanced(): void
    {
        $trialBalance = new TrialBalance($this->tenantId, new \DateTimeImmutable('2026-08-31'), $this->myr, [
            $this->balance('account-cash', AccountType::Asset, '150.00', '0.00'),
            $this->balance('account-office-supplies', AccountType::Expense, '50.00', '0.00'),
            $this->balance('account-consulting-revenue', AccountType::Revenue, '0.00', '200.00'),
        ]);

        $this->assertTrue($trialBalance->isBalanced());
        $this->assertSame('200.00', $trialBalance->totalDebit()->toDecimalString());
        $this->assertSame('200.00', $trialBalance->totalCredit()->toDecimalString());
    }

    public function test_unbalanced_lines_report_as_unbalanced(): void
    {
        $trialBalance = new TrialBalance($this->tenantId, new \DateTimeImmutable('2026-08-31'), $this->myr, [
            $this->balance('account-cash', AccountType::Asset, '150.00', '0.00'),
            $this->balance('account-consulting-revenue', AccountType::Revenue, '0.00', '100.00'),
        ]);

        $this->assertFalse($trialBalance->isBalanced());
    }

    public function test_an_account_with_zero_activity_still_contributes_zero(): void
    {
        $zero = Money::fromDecimalString('0.00', $this->myr);
        $trialBalance = new TrialBalance($this->tenantId, new \DateTimeImmutable('2026-08-31'), $this->myr, [
            new AccountBalance(AccountId::of('account-unused'), AccountType::Asset, $zero, $zero),
        ]);

        $this->assertCount(1, $trialBalance->lines());
        $this->assertTrue($trialBalance->isBalanced());
        $this->assertSame('0.00', $trialBalance->totalDebit()->toDecimalString());
    }

    public function test_empty_trial_balance_is_trivially_balanced(): void
    {
        $trialBalance = new TrialBalance($this->tenantId, new \DateTimeImmutable('2026-08-31'), $this->myr, []);

        $this->assertTrue($trialBalance->isBalanced());
        $this->assertSame('0.00', $trialBalance->totalDebit()->toDecimalString());
        $this->assertSame('0.00', $trialBalance->totalCredit()->toDecimalString());
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
