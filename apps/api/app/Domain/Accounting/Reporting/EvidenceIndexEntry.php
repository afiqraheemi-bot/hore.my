<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\SourceReference;

/**
 * One Posted Journal's Evidence-linkage status for the Evidence Index
 * (AETS-009 §10, SRS RPT-008) — its Source reference, its own balanced
 * amount (AETS-009 v1.10.0 — presentation of an already-guaranteed-
 * correct value, no new computation), and every linked Evidence
 * Reference (AETS-010 §8–§9), `[]` when none.
 *
 * **An empty list is reported plainly, never flagged as a defect.**
 * Per AETS-010 §9, a Journal with no independent evidence of its own
 * (for example, a pure Reversal) legitimately carries none — this class
 * states the fact; it does not classify which absences are expected.
 *
 * **`financialDate` vs `postedAt` (2026-09-21).** `financialDate` is
 * the accounting-effective date (a plain date, no time-of-day) and is
 * what every report grouping/ordering by this class has always used —
 * that stays unchanged here. `postedAt` is the real wall-clock moment
 * the Journal was posted; it exists solely so a *recency* feed (the
 * web app's Home page, which repurposes this same query for "Recent
 * activity") can order same-day entries by when they actually
 * happened rather than leaving ties to whatever order the underlying
 * query happens to return — this class does not itself pick an
 * ordering, it just carries both dates so each caller can choose.
 */
final class EvidenceIndexEntry
{
    /**
     * @param  list<string>  $evidenceReferences
     */
    public function __construct(
        private readonly JournalId $journalId,
        private readonly \DateTimeImmutable $financialDate,
        private readonly \DateTimeImmutable $postedAt,
        private readonly SourceReference $source,
        private readonly Money $amount,
        private readonly array $evidenceReferences,
    ) {}

    public function journalId(): JournalId
    {
        return $this->journalId;
    }

    public function financialDate(): \DateTimeImmutable
    {
        return $this->financialDate;
    }

    public function postedAt(): \DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function source(): SourceReference
    {
        return $this->source;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    /**
     * @return list<string>
     */
    public function evidenceReferences(): array
    {
        return $this->evidenceReferences;
    }

    public function hasEvidence(): bool
    {
        return $this->evidenceReferences !== [];
    }
}
