<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Customers\CustomerId;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\RecordIncomeCommand;

/**
 * A request to record a Payment received from a Customer (M21) —
 * mirrors {@see RecordIncomeCommand}'s
 * own shape and reasoning exactly: a pure data carrier, no I/O, no
 * validation beyond construction-level typing.
 */
final class RecordPaymentCommand
{
    public function __construct(
        private readonly PaymentId $paymentId,
        private readonly JournalId $journalId,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly TenantId $tenantId,
        private readonly ActorReference $actor,
        private readonly CustomerId $customerId,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $paymentDate,
        private readonly AccountId $depositAccountId,
        private readonly AccountId $receivableAccountId,
        private readonly ?string $reference = null,
    ) {}

    public function paymentId(): PaymentId
    {
        return $this->paymentId;
    }

    public function journalId(): JournalId
    {
        return $this->journalId;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function actor(): ActorReference
    {
        return $this->actor;
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
}
