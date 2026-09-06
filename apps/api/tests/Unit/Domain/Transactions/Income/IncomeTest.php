<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Transactions\Income;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\Exception\InvalidIncomeDescriptionException;
use App\Domain\Transactions\Income\Income;
use App\Domain\Transactions\Income\IncomeId;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `Income` record's own construction-level invariants (M9)
 * — the business-context fields it must preserve after posting, and the
 * one validation rule it owns (a non-empty, bounded description).
 */
final class IncomeTest extends TestCase
{
    private TenantId $tenantId;

    private IncomeId $incomeId;

    private JournalId $journalId;

    private Money $amount;

    private \DateTimeImmutable $transactionDate;

    private AccountId $incomeAccountId;

    private AccountId $depositAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = TenantId::of('tenant-0001');
        $this->incomeId = IncomeId::of('income-0001');
        $this->journalId = JournalId::of('journal-0001');
        $this->amount = Money::fromDecimalString('50.00', Currency::of('MYR'));
        $this->transactionDate = new \DateTimeImmutable('2026-09-06');
        $this->incomeAccountId = AccountId::of('account-sales-revenue');
        $this->depositAccountId = AccountId::of('account-cash');
    }

    public function test_records_every_field_exactly(): void
    {
        $evidence = EvidenceReference::of('evidence-0001');

        $income = Income::record(
            $this->incomeId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->incomeAccountId,
            $this->depositAccountId,
            'Consulting income received',
            $evidence,
        );

        $this->assertTrue($income->id()->equals($this->incomeId));
        $this->assertTrue($income->tenantId()->equals($this->tenantId));
        $this->assertTrue($income->journalId()->equals($this->journalId));
        $this->assertTrue($income->amount()->equals($this->amount));
        $this->assertSame('2026-09-06', $income->transactionDate()->format('Y-m-d'));
        $this->assertTrue($income->incomeAccountId()->equals($this->incomeAccountId));
        $this->assertTrue($income->depositAccountId()->equals($this->depositAccountId));
        $this->assertSame('Consulting income received', $income->description());
        $this->assertTrue($income->evidenceReference()?->equals($evidence));
    }

    public function test_evidence_reference_is_optional(): void
    {
        $income = Income::record(
            $this->incomeId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->incomeAccountId,
            $this->depositAccountId,
            'Cash sale',
            null,
        );

        $this->assertNull($income->evidenceReference());
    }

    public function test_empty_description_is_rejected(): void
    {
        $this->expectException(InvalidIncomeDescriptionException::class);

        Income::record(
            $this->incomeId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->incomeAccountId,
            $this->depositAccountId,
            '',
            null,
        );
    }

    public function test_description_exceeding_the_max_length_is_rejected(): void
    {
        $this->expectException(InvalidIncomeDescriptionException::class);

        Income::record(
            $this->incomeId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->incomeAccountId,
            $this->depositAccountId,
            str_repeat('a', 1001),
            null,
        );
    }

    public function test_description_at_exactly_the_max_length_is_accepted(): void
    {
        $income = Income::record(
            $this->incomeId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->incomeAccountId,
            $this->depositAccountId,
            str_repeat('a', 1000),
            null,
        );

        $this->assertSame(1000, strlen($income->description()));
    }

    public function test_reconstitute_restores_every_field_without_revalidating(): void
    {
        $income = Income::reconstitute(
            $this->incomeId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->incomeAccountId,
            $this->depositAccountId,
            'Already-persisted description',
            null,
        );

        $this->assertTrue($income->id()->equals($this->incomeId));
        $this->assertSame('Already-persisted description', $income->description());
    }

    public function test_equals_is_identity_based(): void
    {
        $a = Income::record(
            $this->incomeId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->incomeAccountId,
            $this->depositAccountId,
            'Description A',
            null,
        );
        $b = Income::record(
            $this->incomeId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->incomeAccountId,
            $this->depositAccountId,
            'Description B (different, same identity)',
            null,
        );

        $this->assertTrue($a->equals($b));
    }

    public function test_no_public_mutator_exists(): void
    {
        $reflection = new \ReflectionClass(Income::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith('set', $method->getName());
        }
    }
}
