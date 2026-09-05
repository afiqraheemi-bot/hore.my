<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * A request to reverse an already-Posted Journal (M5, AETS-004 §16):
 * the caller supplies the new Reversal Journal's own identity and
 * which Journal it reverses — never the Reversal's lines, which
 * `Journal::reverse()` derives mechanically from the original (same
 * Account, same Money, same Currency, Direction flipped). Structurally
 * distinct from
 * `PostingCommand`: this is not a Posting Command, and none of its
 * fields are ever added to `PostingCommand` (M5 architecture decision
 * #1) — correction metadata rides entirely on the Journal aggregate
 * this command's execution eventually produces.
 *
 * Pure data carrier — construction-level only. It performs no I/O,
 * validates no Tenant ownership, does not confirm the referenced
 * Journal exists or is eligible to be reversed, and decides nothing
 * about idempotency or persistence. Every one of those is
 * {@see JournalCorrectionCandidateAssembler}'s and
 * {@see JournalCorrectionTransactionalExecutor}'s job.
 */
final class ReverseJournalCommand
{
    public function __construct(
        private readonly IdempotencyKey $idempotencyKey,
        private readonly TenantId $tenantId,
        private readonly JournalId $newJournalId,
        private readonly JournalId $originalJournalId,
    ) {}

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function newJournalId(): JournalId
    {
        return $this->newJournalId;
    }

    public function originalJournalId(): JournalId
    {
        return $this->originalJournalId;
    }
}
