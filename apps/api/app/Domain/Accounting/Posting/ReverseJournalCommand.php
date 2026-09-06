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
 * **Actor and Source (M6, AETS-010 §10).** Every successful Reversal
 * MUST produce an Audit Event capturing Actor and Source (AETS-010
 * §7, §10) — reusing exactly the same `ActorReference`/`SourceReference`
 * contract `PostingCommand` already carries (§6), never a second
 * representation.
 *
 * **Financial date (M8, AETS-004 §16).** The caller also supplies the
 * new Reversal Journal's own `financialDate` — never derived from the
 * original Journal's date, never defaulted to "today". Which date a
 * Reversal should carry is a Posting Rules/Period Management policy
 * decision (AETS-006, deferred) made by the calling layer, not
 * something this command, {@see JournalCorrectionCandidateAssembler},
 * or {@see App\Domain\Accounting\Journal\Journal::reverse()} decide on
 * the caller's behalf.
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
        private readonly ActorReference $actor,
        private readonly SourceReference $source,
        private readonly JournalId $newJournalId,
        private readonly JournalId $originalJournalId,
        private readonly \DateTimeImmutable $financialDate,
    ) {}

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

    public function source(): SourceReference
    {
        return $this->source;
    }

    public function newJournalId(): JournalId
    {
        return $this->newJournalId;
    }

    public function originalJournalId(): JournalId
    {
        return $this->originalJournalId;
    }

    public function financialDate(): \DateTimeImmutable
    {
        return $this->financialDate;
    }
}
