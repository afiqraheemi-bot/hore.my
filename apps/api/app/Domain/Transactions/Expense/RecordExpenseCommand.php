<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A request to record a manually-entered Expense (M7): the caller
 * supplies the new Expense's own identity, the new Journal's identity
 * it will produce, and every field SRS TRX-004 requires be editable
 * before posting (amount, transaction date, Expense Account, Payment
 * Account, description, and an optional Evidence reference).
 *
 * **Transactions domain, not Accounting Core.** This is not a
 * `PostingCommand`, and none of its fields are ever added to
 * `PostingCommand` — {@see ExpenseToPostingCommandTranslator} is the
 * only bridge between the two, mirroring exactly how M5's
 * `ReverseJournalCommand`/`ReplaceJournalCommand` never became
 * `PostingCommand` fields either.
 *
 * **No AI/OCR/Proposal step.** This command represents a fully
 * confirmed, directly-authored intent — SRS's Draft -> Needs
 * Information -> Ready for Confirmation -> Accepted proposal lifecycle
 * (10.3) collapses to a single step here, since M7 builds manual entry
 * only (no Document Processing or AI Orchestration exists yet to
 * produce an intermediate proposal). A future AI-assisted expense flow
 * would confirm an Accounting Proposal into a command shaped like this
 * one — it would not change this command's own contract.
 *
 * **No Source Fingerprint field.** Per AETS-007 §6.2, a purely manual
 * command "authored directly by an Actor with no external source
 * material behind it, has no source to fingerprint and carries none."
 * An Evidence reference is not itself external *source data* requiring
 * duplicate-source detection (no real file-upload/import pipeline
 * exists yet to define what a duplicate would even mean) — see the M7
 * closure report for the full policy reasoning.
 *
 * Pure data carrier — construction-level only. It performs no I/O,
 * validates no Account existence/type/eligibility, and decides nothing
 * about idempotency or persistence — every one of those is
 * {@see ExpenseAccountTypeValidator}'s and
 * {@see ExpenseRecordingService}'s job.
 */
final class RecordExpenseCommand
{
    public function __construct(
        private readonly ExpenseId $expenseId,
        private readonly JournalId $journalId,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly TenantId $tenantId,
        private readonly ActorReference $actor,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly AccountId $expenseAccountId,
        private readonly AccountId $paymentAccountId,
        private readonly string $description,
        private readonly ?EvidenceReference $evidenceReference = null,
    ) {}

    public function expenseId(): ExpenseId
    {
        return $this->expenseId;
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

    public function amount(): Money
    {
        return $this->amount;
    }

    public function transactionDate(): \DateTimeImmutable
    {
        return $this->transactionDate;
    }

    public function expenseAccountId(): AccountId
    {
        return $this->expenseAccountId;
    }

    public function paymentAccountId(): AccountId
    {
        return $this->paymentAccountId;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function evidenceReference(): ?EvidenceReference
    {
        return $this->evidenceReference;
    }
}
