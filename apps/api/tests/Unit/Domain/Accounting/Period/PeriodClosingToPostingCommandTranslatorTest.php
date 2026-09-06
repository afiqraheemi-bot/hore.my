<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Period;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Period\Exception\NothingToCloseException;
use App\Domain\Accounting\Period\PeriodClosingCommand;
use App\Domain\Accounting\Period\PeriodClosingToPostingCommandTranslator;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Accounting\Reporting\AccountBalance;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see PeriodClosingToPostingCommandTranslator}'s own
 * closing-entry algebra (AETS-014) at the pure construction level — no
 * persistence, no query.
 */
final class PeriodClosingToPostingCommandTranslatorTest extends TestCase
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
     * A profit (Revenue > Expense) produces a Credit plug on Retained
     * Earnings — increasing Equity, as a profit should.
     */
    public function test_a_profit_produces_a_credit_plug_on_retained_earnings(): void
    {
        $command = $this->makeCommand();

        $postingCommand = (new PeriodClosingToPostingCommandTranslator)->translate(
            $command,
            [
                $this->balance('account-revenue', AccountType::Revenue, '0.00', '200.00'),
                $this->balance('account-expense', AccountType::Expense, '50.00', '0.00'),
            ],
            $this->myr,
        );

        $lines = $postingCommand->lines();
        $this->assertCount(3, $lines);

        // Revenue zeroed by a Debit of 200.00.
        $this->assertTrue($lines[0]->accountId()->equals(AccountId::of('account-revenue')));
        $this->assertSame(JournalDirection::Debit, $lines[0]->direction());
        $this->assertSame('200.00', $lines[0]->money()->toDecimalString());

        // Expense zeroed by a Credit of 50.00.
        $this->assertTrue($lines[1]->accountId()->equals(AccountId::of('account-expense')));
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertSame('50.00', $lines[1]->money()->toDecimalString());

        // Plug: Credit Retained Earnings for the 150.00 profit.
        $this->assertTrue($lines[2]->accountId()->equals(AccountId::of('account-retained-earnings')));
        $this->assertSame(JournalDirection::Credit, $lines[2]->direction());
        $this->assertSame('150.00', $lines[2]->money()->toDecimalString());
    }

    /**
     * A loss (Expense > Revenue) produces a Debit plug on Retained
     * Earnings — decreasing Equity, as a loss should.
     */
    public function test_a_loss_produces_a_debit_plug_on_retained_earnings(): void
    {
        $command = $this->makeCommand();

        $postingCommand = (new PeriodClosingToPostingCommandTranslator)->translate(
            $command,
            [
                $this->balance('account-revenue', AccountType::Revenue, '0.00', '50.00'),
                $this->balance('account-expense', AccountType::Expense, '200.00', '0.00'),
            ],
            $this->myr,
        );

        $lines = $postingCommand->lines();
        $plug = $lines[2];

        $this->assertTrue($plug->accountId()->equals(AccountId::of('account-retained-earnings')));
        $this->assertSame(JournalDirection::Debit, $plug->direction());
        $this->assertSame('150.00', $plug->money()->toDecimalString());
    }

    /**
     * An exact break-even (Revenue === Expense) produces no plug line
     * at all — the zeroing lines alone already balance, and Retained
     * Earnings is correctly left untouched.
     */
    public function test_an_exact_break_even_produces_no_plug_line(): void
    {
        $command = $this->makeCommand();

        $postingCommand = (new PeriodClosingToPostingCommandTranslator)->translate(
            $command,
            [
                $this->balance('account-revenue', AccountType::Revenue, '0.00', '100.00'),
                $this->balance('account-expense', AccountType::Expense, '100.00', '0.00'),
            ],
            $this->myr,
        );

        $this->assertCount(2, $postingCommand->lines());
    }

    /**
     * A Revenue Account netting its own non-normal Direction (Debit,
     * e.g. after an oversized Reversal) is zeroed by a Credit line and
     * contributes to the "credit" pool of the plug computation exactly
     * as an Expense Account normally would — proving the algebra is
     * direction-agnostic, not merely correct for the expected case.
     */
    public function test_a_revenue_account_netting_debit_direction_is_handled_correctly(): void
    {
        $command = $this->makeCommand();

        // Revenue nets Debit 30.00 (unusual); Expense nets Debit 50.00
        // (normal). True combined effect: a loss of 80.00.
        $postingCommand = (new PeriodClosingToPostingCommandTranslator)->translate(
            $command,
            [
                $this->balance('account-revenue', AccountType::Revenue, '30.00', '0.00'),
                $this->balance('account-expense', AccountType::Expense, '50.00', '0.00'),
            ],
            $this->myr,
        );

        $lines = $postingCommand->lines();

        // Revenue (netting Debit) is zeroed by a Credit line.
        $this->assertSame(JournalDirection::Credit, $lines[0]->direction());
        $this->assertSame('30.00', $lines[0]->money()->toDecimalString());

        // Expense (netting Debit, normal) is zeroed by a Credit line.
        $this->assertSame(JournalDirection::Credit, $lines[1]->direction());
        $this->assertSame('50.00', $lines[1]->money()->toDecimalString());

        // Plug: Debit Retained Earnings for the 80.00 combined loss.
        $plug = $lines[2];
        $this->assertSame(JournalDirection::Debit, $plug->direction());
        $this->assertSame('80.00', $plug->money()->toDecimalString());
    }

    /**
     * Zero-activity Accounts are skipped entirely — they contribute no
     * line — and if every supplied balance is zero, the whole closing
     * is rejected as {@see NothingToCloseException} rather than
     * fabricating an empty or single-line Journal.
     */
    public function test_zero_activity_accounts_are_skipped_and_nothing_to_close_is_rejected(): void
    {
        $command = $this->makeCommand();
        $zero = Money::fromDecimalString('0.00', $this->myr);

        $this->expectException(NothingToCloseException::class);

        (new PeriodClosingToPostingCommandTranslator)->translate(
            $command,
            [new AccountBalance(AccountId::of('account-revenue'), AccountType::Revenue, $zero, $zero)],
            $this->myr,
        );
    }

    private function makeCommand(): PeriodClosingCommand
    {
        return new PeriodClosingCommand(
            $this->tenantId,
            IdempotencyKey::of('key-close-0001'),
            ActorReference::of('actor-0001'),
            JournalId::of('journal-closing-0001'),
            new \DateTimeImmutable('2026-08-31'),
            AccountId::of('account-retained-earnings'),
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
