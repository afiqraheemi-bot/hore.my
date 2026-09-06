<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Transactions\Transfer;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Transfer\Exception\InvalidTransferDescriptionException;
use App\Domain\Transactions\Transfer\Transfer;
use App\Domain\Transactions\Transfer\TransferId;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `Transfer` record's own construction-level invariants
 * (M14) — the business-context fields it must preserve after posting,
 * and the one validation rule it owns (a non-empty, bounded
 * description).
 */
final class TransferTest extends TestCase
{
    private TenantId $tenantId;

    private TransferId $transferId;

    private JournalId $journalId;

    private Money $amount;

    private \DateTimeImmutable $transactionDate;

    private AccountId $sourceAccountId;

    private AccountId $destinationAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = TenantId::of('tenant-0001');
        $this->transferId = TransferId::of('transfer-0001');
        $this->journalId = JournalId::of('journal-0001');
        $this->amount = Money::fromDecimalString('50.00', Currency::of('MYR'));
        $this->transactionDate = new \DateTimeImmutable('2026-09-07');
        $this->sourceAccountId = AccountId::of('account-bank');
        $this->destinationAccountId = AccountId::of('account-petty-cash');
    }

    public function test_records_every_field_exactly(): void
    {
        $evidence = EvidenceReference::of('evidence-0001');

        $transfer = Transfer::record(
            $this->transferId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->sourceAccountId,
            $this->destinationAccountId,
            'Move float to petty cash',
            $evidence,
        );

        $this->assertTrue($transfer->id()->equals($this->transferId));
        $this->assertTrue($transfer->tenantId()->equals($this->tenantId));
        $this->assertTrue($transfer->journalId()->equals($this->journalId));
        $this->assertTrue($transfer->amount()->equals($this->amount));
        $this->assertSame('2026-09-07', $transfer->transactionDate()->format('Y-m-d'));
        $this->assertTrue($transfer->sourceAccountId()->equals($this->sourceAccountId));
        $this->assertTrue($transfer->destinationAccountId()->equals($this->destinationAccountId));
        $this->assertSame('Move float to petty cash', $transfer->description());
        $this->assertTrue($transfer->evidenceReference()?->equals($evidence));
    }

    public function test_evidence_reference_is_optional(): void
    {
        $transfer = Transfer::record(
            $this->transferId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->sourceAccountId,
            $this->destinationAccountId,
            'Bank to cash',
            null,
        );

        $this->assertNull($transfer->evidenceReference());
    }

    public function test_empty_description_is_rejected(): void
    {
        $this->expectException(InvalidTransferDescriptionException::class);

        Transfer::record(
            $this->transferId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->sourceAccountId,
            $this->destinationAccountId,
            '',
            null,
        );
    }

    public function test_description_exceeding_the_max_length_is_rejected(): void
    {
        $this->expectException(InvalidTransferDescriptionException::class);

        Transfer::record(
            $this->transferId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->sourceAccountId,
            $this->destinationAccountId,
            str_repeat('a', 1001),
            null,
        );
    }

    public function test_description_at_exactly_the_max_length_is_accepted(): void
    {
        $transfer = Transfer::record(
            $this->transferId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->sourceAccountId,
            $this->destinationAccountId,
            str_repeat('a', 1000),
            null,
        );

        $this->assertSame(1000, strlen($transfer->description()));
    }

    public function test_reconstitute_restores_every_field_without_revalidating(): void
    {
        $transfer = Transfer::reconstitute(
            $this->transferId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->sourceAccountId,
            $this->destinationAccountId,
            'Already-persisted description',
            null,
        );

        $this->assertTrue($transfer->id()->equals($this->transferId));
        $this->assertSame('Already-persisted description', $transfer->description());
    }

    public function test_equals_is_identity_based(): void
    {
        $a = Transfer::record(
            $this->transferId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->sourceAccountId,
            $this->destinationAccountId,
            'Description A',
            null,
        );
        $b = Transfer::record(
            $this->transferId,
            $this->tenantId,
            $this->journalId,
            $this->amount,
            $this->transactionDate,
            $this->sourceAccountId,
            $this->destinationAccountId,
            'Description B (different, same identity)',
            null,
        );

        $this->assertTrue($a->equals($b));
    }

    public function test_no_public_mutator_exists(): void
    {
        $reflection = new \ReflectionClass(Transfer::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith('set', $method->getName());
        }
    }
}
