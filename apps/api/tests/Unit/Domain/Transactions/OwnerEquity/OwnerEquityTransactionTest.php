<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidOwnerEquityTransactionDescriptionException;
use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransaction;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionId;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `OwnerEquityTransaction` record's own construction-level
 * invariants (M15) — the business-context fields it must preserve
 * after posting, and the one validation rule it owns (a non-empty,
 * bounded description).
 */
final class OwnerEquityTransactionTest extends TestCase
{
    private TenantId $tenantId;

    private OwnerEquityTransactionId $transactionId;

    private JournalId $journalId;

    private Money $amount;

    private \DateTimeImmutable $transactionDate;

    private AccountId $equityAccountId;

    private AccountId $cashAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = TenantId::of('tenant-0001');
        $this->transactionId = OwnerEquityTransactionId::of('owner-equity-0001');
        $this->journalId = JournalId::of('journal-0001');
        $this->amount = Money::fromDecimalString('500.00', Currency::of('MYR'));
        $this->transactionDate = new \DateTimeImmutable('2026-09-07');
        $this->equityAccountId = AccountId::of('account-owner-capital');
        $this->cashAccountId = AccountId::of('account-bank');
    }

    public function test_records_every_field_exactly(): void
    {
        $evidence = EvidenceReference::of('evidence-0001');

        $transaction = OwnerEquityTransaction::record(
            $this->transactionId,
            $this->tenantId,
            $this->journalId,
            OwnerEquityMovementType::Contribution,
            $this->amount,
            $this->transactionDate,
            $this->equityAccountId,
            $this->cashAccountId,
            'Owner injected startup capital',
            $evidence,
        );

        $this->assertTrue($transaction->id()->equals($this->transactionId));
        $this->assertTrue($transaction->tenantId()->equals($this->tenantId));
        $this->assertTrue($transaction->journalId()->equals($this->journalId));
        $this->assertSame(OwnerEquityMovementType::Contribution, $transaction->movementType());
        $this->assertTrue($transaction->amount()->equals($this->amount));
        $this->assertSame('2026-09-07', $transaction->transactionDate()->format('Y-m-d'));
        $this->assertTrue($transaction->equityAccountId()->equals($this->equityAccountId));
        $this->assertTrue($transaction->cashAccountId()->equals($this->cashAccountId));
        $this->assertSame('Owner injected startup capital', $transaction->description());
        $this->assertTrue($transaction->evidenceReference()?->equals($evidence));
    }

    public function test_evidence_reference_is_optional(): void
    {
        $transaction = OwnerEquityTransaction::record(
            $this->transactionId,
            $this->tenantId,
            $this->journalId,
            OwnerEquityMovementType::Drawing,
            $this->amount,
            $this->transactionDate,
            $this->equityAccountId,
            $this->cashAccountId,
            'Owner withdrew funds',
            null,
        );

        $this->assertNull($transaction->evidenceReference());
    }

    public function test_empty_description_is_rejected(): void
    {
        $this->expectException(InvalidOwnerEquityTransactionDescriptionException::class);

        OwnerEquityTransaction::record(
            $this->transactionId,
            $this->tenantId,
            $this->journalId,
            OwnerEquityMovementType::Contribution,
            $this->amount,
            $this->transactionDate,
            $this->equityAccountId,
            $this->cashAccountId,
            '',
            null,
        );
    }

    public function test_description_exceeding_the_max_length_is_rejected(): void
    {
        $this->expectException(InvalidOwnerEquityTransactionDescriptionException::class);

        OwnerEquityTransaction::record(
            $this->transactionId,
            $this->tenantId,
            $this->journalId,
            OwnerEquityMovementType::Contribution,
            $this->amount,
            $this->transactionDate,
            $this->equityAccountId,
            $this->cashAccountId,
            str_repeat('a', 1001),
            null,
        );
    }

    public function test_description_at_exactly_the_max_length_is_accepted(): void
    {
        $transaction = OwnerEquityTransaction::record(
            $this->transactionId,
            $this->tenantId,
            $this->journalId,
            OwnerEquityMovementType::Contribution,
            $this->amount,
            $this->transactionDate,
            $this->equityAccountId,
            $this->cashAccountId,
            str_repeat('a', 1000),
            null,
        );

        $this->assertSame(1000, strlen($transaction->description()));
    }

    public function test_reconstitute_restores_every_field_without_revalidating(): void
    {
        $transaction = OwnerEquityTransaction::reconstitute(
            $this->transactionId,
            $this->tenantId,
            $this->journalId,
            OwnerEquityMovementType::Drawing,
            $this->amount,
            $this->transactionDate,
            $this->equityAccountId,
            $this->cashAccountId,
            'Already-persisted description',
            null,
        );

        $this->assertTrue($transaction->id()->equals($this->transactionId));
        $this->assertSame(OwnerEquityMovementType::Drawing, $transaction->movementType());
        $this->assertSame('Already-persisted description', $transaction->description());
    }

    public function test_equals_is_identity_based(): void
    {
        $a = OwnerEquityTransaction::record(
            $this->transactionId,
            $this->tenantId,
            $this->journalId,
            OwnerEquityMovementType::Contribution,
            $this->amount,
            $this->transactionDate,
            $this->equityAccountId,
            $this->cashAccountId,
            'Description A',
            null,
        );
        $b = OwnerEquityTransaction::record(
            $this->transactionId,
            $this->tenantId,
            $this->journalId,
            OwnerEquityMovementType::Contribution,
            $this->amount,
            $this->transactionDate,
            $this->equityAccountId,
            $this->cashAccountId,
            'Description B (different, same identity)',
            null,
        );

        $this->assertTrue($a->equals($b));
    }

    public function test_no_public_mutator_exists(): void
    {
        $reflection = new \ReflectionClass(OwnerEquityTransaction::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith('set', $method->getName());
        }
    }
}
