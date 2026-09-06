<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Reporting;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\SourceReference;

/**
 * One Journal Line's contribution to a single Account's General Ledger
 * drill-down (AETS-009 §9, SRS RPT-005) — the Journal it belongs to,
 * that Journal's `financialDate`/`postedAt`, the Line's own Money and
 * Direction, its Source, and any linked Evidence References, so a user
 * can trace any reported number back to the exact Journal and its
 * original evidence.
 */
final class GeneralLedgerEntry
{
    /**
     * @param  list<string>  $evidenceReferences  Opaque Evidence Reference values (AETS-010 §8), `[]` when none are linked.
     */
    public function __construct(
        private readonly JournalId $journalId,
        private readonly \DateTimeImmutable $financialDate,
        private readonly \DateTimeImmutable $postedAt,
        private readonly Money $amount,
        private readonly JournalDirection $direction,
        private readonly SourceReference $source,
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

    public function amount(): Money
    {
        return $this->amount;
    }

    public function direction(): JournalDirection
    {
        return $this->direction;
    }

    public function source(): SourceReference
    {
        return $this->source;
    }

    /**
     * @return list<string>
     */
    public function evidenceReferences(): array
    {
        return $this->evidenceReferences;
    }
}
