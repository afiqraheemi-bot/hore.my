<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\Income;
use App\Domain\Transactions\Transfer\Exception\InvalidTransferDescriptionException;

/**
 * The authoritative Transfer record (M14): the Transactions-domain
 * business context a manually-recorded transfer between two of the
 * Tenant's own Accounts retains *after* it has produced a Posted
 * Journal — so the amount, transaction date, Source Account,
 * Destination Account, description, and Evidence reference remain
 * traceable and human-readable long after the resulting Journal (which
 * carries only opaque Account/Money/Direction pairs, per AETS-004 §7)
 * would otherwise be the only surviving record.
 *
 * **A record, not a workflow.** Mirrors {@see Income}'s
 * own reasoning exactly: a Transfer exists if and only if its Journal
 * was successfully Posted — there is no intermediate Draft state.
 *
 * **Immutable, mirroring a Posted Journal's own append-only nature.**
 * There is no public mutator. Once recorded, a Transfer's fields never
 * change — a mistake is corrected by reversing/replacing its Journal
 * (M5), never by editing this record in place.
 *
 * **`journalId` is always present, never nullable** — see
 * {@see TransferRecordingService}'s own docblock for the atomicity
 * argument.
 */
final class Transfer
{
    private const MAX_DESCRIPTION_LENGTH = 1000;

    private function __construct(
        private readonly TransferId $id,
        private readonly TenantId $tenantId,
        private readonly JournalId $journalId,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly AccountId $sourceAccountId,
        private readonly AccountId $destinationAccountId,
        private readonly string $description,
        private readonly ?EvidenceReference $evidenceReference,
    ) {}

    /**
     * @throws InvalidTransferDescriptionException if `$description` is
     *                                             empty or exceeds the defensive length bound.
     */
    public static function record(
        TransferId $id,
        TenantId $tenantId,
        JournalId $journalId,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $sourceAccountId,
        AccountId $destinationAccountId,
        string $description,
        ?EvidenceReference $evidenceReference,
    ): self {
        if ($description === '') {
            throw InvalidTransferDescriptionException::forEmpty();
        }

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw InvalidTransferDescriptionException::forExceedingMaxLength(self::MAX_DESCRIPTION_LENGTH);
        }

        return new self(
            $id,
            $tenantId,
            $journalId,
            $amount,
            $transactionDate,
            $sourceAccountId,
            $destinationAccountId,
            $description,
            $evidenceReference,
        );
    }

    /**
     * Reconstruct an already-recorded Transfer from persisted state —
     * no validation beyond each field's own Value Object, mirroring
     * {@see Journal::reconstitute()}'s own reasoning: the supplied
     * state was already validated once, at the point it was originally
     * recorded.
     */
    public static function reconstitute(
        TransferId $id,
        TenantId $tenantId,
        JournalId $journalId,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $sourceAccountId,
        AccountId $destinationAccountId,
        string $description,
        ?EvidenceReference $evidenceReference,
    ): self {
        return new self(
            $id,
            $tenantId,
            $journalId,
            $amount,
            $transactionDate,
            $sourceAccountId,
            $destinationAccountId,
            $description,
            $evidenceReference,
        );
    }

    public function id(): TransferId
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

    public function amount(): Money
    {
        return $this->amount;
    }

    public function transactionDate(): \DateTimeImmutable
    {
        return $this->transactionDate;
    }

    public function sourceAccountId(): AccountId
    {
        return $this->sourceAccountId;
    }

    public function destinationAccountId(): AccountId
    {
        return $this->destinationAccountId;
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
