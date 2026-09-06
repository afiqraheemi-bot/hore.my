<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Reporting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Reporting\AccountBalance;
use PHPUnit\Framework\TestCase;

final class AccountBalanceTest extends TestCase
{
    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
    }

    public function test_preserves_account_id_and_type_exactly(): void
    {
        $id = AccountId::of('account-cash');
        $balance = new AccountBalance(
            $id,
            AccountType::Asset,
            Money::fromDecimalString('100.00', $this->myr),
            Money::fromDecimalString('0.00', $this->myr),
        );

        $this->assertTrue($id->equals($balance->accountId()));
        $this->assertSame(AccountType::Asset, $balance->accountType());
    }

    public function test_computes_net_balance_from_totals(): void
    {
        $balance = new AccountBalance(
            AccountId::of('account-cash'),
            AccountType::Asset,
            Money::fromDecimalString('150.00', $this->myr),
            Money::fromDecimalString('50.00', $this->myr),
        );

        $this->assertSame('100.00', $balance->netBalance()->amount()->toDecimalString());
        $this->assertSame(JournalDirection::Debit, $balance->netBalance()->direction());
    }

    public function test_zero_activity_account_has_zero_net_balance(): void
    {
        $zero = Money::fromDecimalString('0.00', $this->myr);
        $balance = new AccountBalance(AccountId::of('account-unused'), AccountType::Expense, $zero, $zero);

        $this->assertTrue($balance->netBalance()->isZero());
    }
}
