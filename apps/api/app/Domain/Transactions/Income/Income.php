<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\Exception\InvalidIncomeDescriptionException;

/**
 * The authoritative Income record (M9): the Transactions-domain
 * business context a manually-recorded income retains *after* it has
 * produced a Posted Journal — so the amount, transaction date, Income
 * Account, Deposit Account, description, and Evidence reference remain
 * traceable and human-readable long after the resulting Journal (which
 * carries only opaque Account/Money/Direction pairs, per AETS-004 §7)
 * would otherwise be the only surviving record.
 *
 * **A record, not a workflow.** Unlike SRS's own Proposal state machine
 * (10.3: Draft -> Needs Information -> Ready for Confirmation ->
 * Accepted -> Posted), an `Income` in M9 has exactly one state: it
 * exists if and only if its Journal was successfully Posted — there is
 * no intermediate Draft Income, because M9 builds manual, directly-
 * confirmed entry only (no AI/OCR proposal stage exists yet to justify
 * one). A future AI-assisted flow that introduces genuine pre-posting
 * review would need its own Draft-Income representation — this class
 * does not anticipate that shape speculatively.
 *
 * **Immutable, mirroring a Posted Journal's own append-only nature.**
 * There is no public mutator. Once recorded, an Income's fields never
 * change — a mistake is corrected by reversing/replacing its Journal
 * (M5), never by editing this record in place, exactly as Posted
 * Journal history itself is never edited (AETS-004 §15).
 *
 * **`journalId` is always present, never nullable.** Because this class
 * has no Draft state, there is no window in which an Income exists
 * without its Journal — {@see IncomeRecordingService} only ever
 * constructs one after `PostingCommandTransactionalExecutor::execute()`
 * has already returned a newly-posted Journal, inside the same atomic
 * transaction (see that service's own docblock for the atomicity
 * argument).
 */
final class Income
{
    private const MAX_DESCRIPTION_LENGTH = 1000;

    private function __construct(
        private readonly IncomeId $id,
        private readonly TenantId $tenantId,
        private readonly JournalId $journalId,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly AccountId $incomeAccountId,
        private readonly AccountId $depositAccountId,
        private readonly string $description,
        private readonly ?EvidenceReference $evidenceReference,
    ) {}

    /**
     * @throws InvalidIncomeDescriptionException if `$description` is
     *                                           empty or exceeds the defensive length bound.
     */
    public static function record(
        IncomeId $id,
        TenantId $tenantId,
        JournalId $journalId,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $incomeAccountId,
        AccountId $depositAccountId,
        string $description,
        ?EvidenceReference $evidenceReference,
    ): self {
        if ($description === '') {
            throw InvalidIncomeDescriptionException::forEmpty();
        }

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw InvalidIncomeDescriptionException::forExceedingMaxLength(self::MAX_DESCRIPTION_LENGTH);
        }

        return new self(
            $id,
            $tenantId,
            $journalId,
            $amount,
            $transactionDate,
            $incomeAccountId,
            $depositAccountId,
            $description,
            $evidenceReference,
        );
    }

    /**
     * Reconstruct an already-recorded Income from persisted state — no
     * validation beyond each field's own Value Object, mirroring
     * {@see Journal::reconstitute()}'s
     * own reasoning: the supplied state was already validated once, at
     * the point it was originally recorded.
     */
    public static function reconstitute(
        IncomeId $id,
        TenantId $tenantId,
        JournalId $journalId,
        Money $amount,
        \DateTimeImmutable $transactionDate,
        AccountId $incomeAccountId,
        AccountId $depositAccountId,
        string $description,
        ?EvidenceReference $evidenceReference,
    ): self {
        return new self(
            $id,
            $tenantId,
            $journalId,
            $amount,
            $transactionDate,
            $incomeAccountId,
            $depositAccountId,
            $description,
            $evidenceReference,
        );
    }

    public function id(): IncomeId
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

    public function incomeAccountId(): AccountId
    {
        return $this->incomeAccountId;
    }

    public function depositAccountId(): AccountId
    {
        return $this->depositAccountId;
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
