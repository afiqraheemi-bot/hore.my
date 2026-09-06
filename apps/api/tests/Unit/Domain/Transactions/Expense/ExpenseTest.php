<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Transactions\Expense;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Expense\Exception\InvalidExpenseDescriptionException;
use App\Domain\Transactions\Expense\Expense;
use App\Domain\Transactions\Expense\ExpenseId;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `Expense` record's own construction-level invariants (M7)
 * — the business-context fields it must preserve after posting, and the
 * one validation rule it owns (a non-empty, bounded description).
 */
final class ExpenseTest extends TestCase
{
    private TenantId $tenantId;

    private ExpenseId $expenseId;

    private JournalId $journalId;

    private Money $amount;

    private \DateTimeImmutable $transactionDate;

    private AccountId $expenseAccountId;

    private AccountId $paymentAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = TenantId::of('tenant-0001');
        $this->expenseId = ExpenseId::of('expense-0001');
        $this->journalId = JournalId::of('journal-0001');
        $this->amount = Money::fromDecimalString('50.00', Currency::of('MYR'));
        $this->transactionDate = new \DateTimeImmutable('2026-09-06');
        $this->expenseAccountId = AccountId::of('account-office-supplies');
        $this->paymentAccountId = AccountId::of('account-cash');
    }

    public function test_records_every_field_exactly(): void
    {
        $evidence = EvidenceReference::of('evidence-0001');

        $expense = Expense::record(
            $this->expenseId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->expenseAccountId,
            $this->paymentAccountId,
            'Parking fee for client meeting',
            $evidence,
        );

        $this->assertTrue($expense->id()->equals($this->expenseId));
        $this->assertTrue($expense->tenantId()->equals($this->tenantId));
        $this->assertTrue($expense->journalId()->equals($this->journalId));
        $this->assertTrue($expense->amount()->equals($this->amount));
        $this->assertSame('2026-09-06', $expense->transactionDate()->format('Y-m-d'));
        $this->assertTrue($expense->expenseAccountId()->equals($this->expenseAccountId));
        $this->assertTrue($expense->paymentAccountId()->equals($this->paymentAccountId));
        $this->assertSame('Parking fee for client meeting', $expense->description());
        $this->assertTrue($expense->evidenceReference()?->equals($evidence));
    }

    public function test_evidence_reference_is_optional(): void
    {
        $expense = Expense::record(
            $this->expenseId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->expenseAccountId,
            $this->paymentAccountId,
            'Office supplies',
            null,
        );

        $this->assertNull($expense->evidenceReference());
    }

    public function test_empty_description_is_rejected(): void
    {
        $this->expectException(InvalidExpenseDescriptionException::class);

        Expense::record(
            $this->expenseId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->expenseAccountId,
            $this->paymentAccountId,
            '',
            null,
        );
    }

    public function test_description_exceeding_the_max_length_is_rejected(): void
    {
        $this->expectException(InvalidExpenseDescriptionException::class);

        Expense::record(
            $this->expenseId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->expenseAccountId,
            $this->paymentAccountId,
            str_repeat('a', 1001),
            null,
        );
    }

    public function test_description_at_exactly_the_max_length_is_accepted(): void
    {
        $expense = Expense::record(
            $this->expenseId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->expenseAccountId,
            $this->paymentAccountId,
            str_repeat('a', 1000),
            null,
        );

        $this->assertSame(1000, strlen($expense->description()));
    }

    public function test_reconstitute_restores_every_field_without_revalidating(): void
    {
        $expense = Expense::reconstitute(
            $this->expenseId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->expenseAccountId,
            $this->paymentAccountId,
            'Already-persisted description',
            null,
        );

        $this->assertTrue($expense->id()->equals($this->expenseId));
        $this->assertSame('Already-persisted description', $expense->description());
    }

    public function test_equals_is_identity_based(): void
    {
        $a = Expense::record(
            $this->expenseId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->expenseAccountId,
            $this->paymentAccountId,
            'Description A',
            null,
        );
        $b = Expense::record(
            $this->expenseId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->expenseAccountId,
            $this->paymentAccountId,
            'Description B (different, same identity)',
            null,
        );

        $this->assertTrue($a->equals($b));
    }

    public function test_no_public_mutator_exists(): void
    {
        $reflection = new \ReflectionClass(Expense::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith('set', $method->getName());
        }
    }
}
