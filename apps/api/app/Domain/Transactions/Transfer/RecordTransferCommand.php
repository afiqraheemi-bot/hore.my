<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Shared\Tenancy\TenantId;
use App\Domain\Transactions\Income\RecordIncomeCommand;

/**
 * A request to record a manually-entered Transfer between two of the
 * Tenant's own Accounts (M14): the caller supplies the new Transfer's
 * own identity, the new Journal's identity it will produce, and every
 * field describing the movement (amount, transaction date, Source
 * Account, Destination Account, description, and an optional Evidence
 * reference).
 *
 * **Transactions domain, not Accounting Core** — mirrors
 * {@see RecordIncomeCommand}'s own
 * reasoning exactly: this is not a `PostingCommand`, and none of its
 * fields are ever added to `PostingCommand`.
 *
 * **No Source Fingerprint field** — per AETS-007 §6.2, a purely manual
 * command has no external source material to fingerprint.
 *
 * Pure data carrier — construction-level only. It performs no I/O,
 * validates no Account existence/type/eligibility, and decides nothing
 * about idempotency or persistence — every one of those is
 * {@see TransferAccountTypeValidator}'s and
 * {@see TransferRecordingService}'s job.
 */
final class RecordTransferCommand
{
    public function __construct(
        private readonly TransferId $transferId,
        private readonly JournalId $journalId,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly TenantId $tenantId,
        private readonly ActorReference $actor,
        private readonly Money $amount,
        private readonly \DateTimeImmutable $transactionDate,
        private readonly AccountId $sourceAccountId,
        private readonly AccountId $destinationAccountId,
        private readonly string $description,
        private readonly ?EvidenceReference $evidenceReference = null,
    ) {}

    public function transferId(): TransferId
    {
        return $this->transferId;
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
}
