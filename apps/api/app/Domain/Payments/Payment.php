<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\CustomerId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\Income;

/**
 * The authoritative Payment record (M21, Modul 7 phase 3): the
 * Invoicing-domain business context a recorded Payment retains *after*
 * it has produced a Posted Journal (Debit deposit Account / Credit
 * Receivable Account) — mirrors
 * {@see Income}'s own "record, not a
 * workflow" shape exactly: a Payment has exactly one state, it exists
 * if and only if its Journal was successfully Posted. There is no
 * Draft Payment — money either arrived or it did not.
 *
 * **Immutable, mirroring a Posted Journal's own append-only nature.**
 * No public mutator. A mistake is corrected by reversing/replacing its
 * Journal (M5), never by editing this record in place.
 *
 * **Carries no link to any Invoice.** Which Invoice(s) this Payment
 * covers is {@see PaymentAllocation}'s own separate concern — a
 * Payment may exist allocated to nothing (unapplied cash).
 */
final class Payment
{
    private const MAX_REFERENCE_LENGTH = 255;

    private function __construct(
        private readonly PaymentId $id,
        private readonly TenantId $tenantId,
        private readonly JournalId $journalId,
        private readonly CustomerId $customerId,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $paymentDate,
        private readonly AccountId $depositAccountId,
        private readonly AccountId $receivableAccountId,
        private readonly ?string $reference,
    ) {}

    public static function record(
        PaymentId $id,
        TenantId $tenantId,
        JournalId $journalId,
        CustomerId $customerId,
        Money $amount,
        \DateTimeImmutable $paymentDate,
        AccountId $depositAccountId,
        AccountId $receivableAccountId,
        ?string $reference,
    ): self {
        if ($reference !== null && strlen($reference) > self::MAX_REFERENCE_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A Payment reference must not exceed %d characters.', self::MAX_REFERENCE_LENGTH));
        }

        return new self($id, $tenantId, $journalId, $customerId, $amount, $paymentDate, $depositAccountId, $receivableAccountId, $reference);
    }

    /**
     * Reconstruct an already-recorded Payment from persisted state —
     * no validation beyond each field's own Value Object, mirroring
     * every other `reconstitute()` in this codebase.
     */
    public static function reconstitute(
        PaymentId $id,
        TenantId $tenantId,
        JournalId $journalId,
        CustomerId $customerId,
        Money $amount,
        \DateTimeImmutable $paymentDate,
        AccountId $depositAccountId,
        AccountId $receivableAccountId,
        ?string $reference,
    ): self {
        return new self($id, $tenantId, $journalId, $customerId, $amount, $paymentDate, $depositAccountId, $receivableAccountId, $reference);
    }

    public function id(): PaymentId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function journalId(): JournalId
    {
        return $this->journalId;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function paymentDate(): \DateTimeImmutable
    {
        return $this->paymentDate;
    }

    public function depositAccountId(): AccountId
    {
        return $this->depositAccountId;
    }

    public function receivableAccountId(): AccountId
    {
        return $this->receivableAccountId;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
