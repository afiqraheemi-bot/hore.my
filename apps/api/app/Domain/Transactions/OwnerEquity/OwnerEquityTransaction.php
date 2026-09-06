<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\Income;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidOwnerEquityTransactionDescriptionException;

/**
 * The authoritative Owner Equity Transaction record (M15): the
 * Transactions-domain business context a manually-recorded Capital
 * Contribution or Drawing retains *after* it has produced a Posted
 * Journal — so the movement type, amount, transaction date, Equity
 * Account, Cash Account, description, and Evidence reference remain
 * traceable and human-readable long after the resulting Journal (which
 * carries only opaque Account/Money/Direction pairs, per AETS-004 §7)
 * would otherwise be the only surviving record.
 *
 * **A record, not a workflow** — mirrors
 * {@see Income}'s own reasoning
 * exactly: it exists if and only if its Journal was successfully
 * Posted, with no intermediate Draft state.
 *
 * **Immutable, mirroring a Posted Journal's own append-only nature.**
 * There is no public mutator. Once recorded, a fact never changes — a
 * mistake is corrected by reversing/replacing its Journal (M5), never
 * by editing this record in place.
 */
final class OwnerEquityTransaction
{
    private const MAX_DESCRIPTION_LENGTH = 1000;

    private function __construct(
        private readonly OwnerEquityTransactionId $id,
        private readonly TenantId $tenantId,
        private readonly JournalId $journalId,
        private readonly OwnerEquityMovementType $movementType,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly AccountId $equityAccountId,
        private readonly AccountId $cashAccountId,
        private readonly string $description,
        private readonly ?EvidenceReference $evidenceReference,
    ) {}

    /**
     * @throws InvalidOwnerEquityTransactionDescriptionException if
     *                                                           `$description` is empty or exceeds the defensive length bound.
     */
    public static function record(
        OwnerEquityTransactionId $id,
        TenantId $tenantId,
        JournalId $journalId,
        OwnerEquityMovementType $movementType,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $equityAccountId,
        AccountId $cashAccountId,
        string $description,
        ?EvidenceReference $evidenceReference,
    ): self {
        if ($description === '') {
            throw InvalidOwnerEquityTransactionDescriptionException::forEmpty();
        }

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw InvalidOwnerEquityTransactionDescriptionException::forExceedingMaxLength(self::MAX_DESCRIPTION_LENGTH);
        }

        return new self(
            $id,
            $tenantId,
            $journalId,
            $movementType,
            $amount,
            $transactionDate,
            $equityAccountId,
            $cashAccountId,
            $description,
            $evidenceReference,
        );
    }

    /**
     * Reconstruct an already-recorded Owner Equity Transaction from
     * persisted state — no validation beyond each field's own Value
     * Object, mirroring {@see Journal::reconstitute()}'s own reasoning.
     */
    public static function reconstitute(
        OwnerEquityTransactionId $id,
        TenantId $tenantId,
        JournalId $journalId,
        OwnerEquityMovementType $movementType,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $equityAccountId,
        AccountId $cashAccountId,
        string $description,
        ?EvidenceReference $evidenceReference,
    ): self {
        return new self(
            $id,
            $tenantId,
            $journalId,
            $movementType,
            $amount,
            $transactionDate,
            $equityAccountId,
            $cashAccountId,
            $description,
            $evidenceReference,
        );
    }

    public function id(): OwnerEquityTransactionId
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

    public function movementType(): OwnerEquityMovementType
    {
        return $this->movementType;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function transactionDate(): \DateTimeImmutable
    {
        return $this->transactionDate;
    }

    public function equityAccountId(): AccountId
    {
        return $this->equityAccountId;
    }

    public function cashAccountId(): AccountId
    {
        return $this->cashAccountId;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function evidenceReference(): ?EvidenceReference
    {
        return $this->evidenceReference;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
