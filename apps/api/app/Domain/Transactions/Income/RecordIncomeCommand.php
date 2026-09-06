<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A request to record a manually-entered Income (M9): the caller
 * supplies the new Income's own identity, the new Journal's identity
 * it will produce, and every field SRS TRX-004 requires be editable
 * before posting (amount, transaction date, Income Account, Deposit
 * Account, description, and an optional Evidence reference).
 *
 * **Transactions domain, not Accounting Core.** This is not a
 * `PostingCommand`, and none of its fields are ever added to
 * `PostingCommand` — {@see IncomeToPostingCommandTranslator} is the
 * only bridge between the two, mirroring exactly how M5's
 * `ReverseJournalCommand`/`ReplaceJournalCommand` never became
 * `PostingCommand` fields either.
 *
 * **No AI/OCR/Proposal step.** This command represents a fully
 * confirmed, directly-authored intent — SRS's Draft -> Needs
 * Information -> Ready for Confirmation -> Accepted proposal lifecycle
 * (10.3) collapses to a single step here, since M9 builds manual entry
 * only (no Document Processing or AI Orchestration exists yet to
 * produce an intermediate proposal). A future AI-assisted income flow
 * would confirm an Accounting Proposal into a command shaped like this
 * one — it would not change this command's own contract.
 *
 * **No Source Fingerprint field.** Per AETS-007 §6.2, a purely manual
 * command "authored directly by an Actor with no external source
 * material behind it, has no source to fingerprint and carries none."
 * An Evidence reference is not itself external *source data* requiring
 * duplicate-source detection (no real file-upload/import pipeline
 * exists yet to define what a duplicate would even mean) — see the M9
 * closure report for the full policy reasoning.
 *
 * Pure data carrier — construction-level only. It performs no I/O,
 * validates no Account existence/type/eligibility, and decides nothing
 * about idempotency or persistence — every one of those is
 * {@see IncomeAccountTypeValidator}'s and
 * {@see IncomeRecordingService}'s job.
 */
final class RecordIncomeCommand
{
    public function __construct(
        private readonly IncomeId $incomeId,
        private readonly JournalId $journalId,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly TenantId $tenantId,
        private readonly ActorReference $actor,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly AccountId $incomeAccountId,
        private readonly AccountId $depositAccountId,
        private readonly string $description,
        private readonly ?EvidenceReference $evidenceReference = null,
    ) {}

    public function incomeId(): IncomeId
    {
        return $this->incomeId;
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
}
