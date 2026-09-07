<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalId;

/**
 * One not-yet-confirmed match suggestion (M18) —
 * {@see BankTransactionMatchSuggester}'s own output type, computed
 * fresh on every call and never itself persisted (only
 * {@see MatchingService::confirm()} persists anything, as a
 * {@see BankTransactionMatch}).
 */
final class MatchCandidate
{
    public function __construct(
        private readonly BankTransactionId $bankTransactionId,
        private readonly JournalId $journalId,
        private readonly MatchSourceType $sourceType,
        private readonly string $rationale,
    ) {}

    public function bankTransactionId(): BankTransactionId
    {
        return $this->bankTransactionId;
    }

    public function journalId(): JournalId
    {
        return $this->journalId;
    }

    public function sourceType(): MatchSourceType
    {
        return $this->sourceType;
    }

    public function rationale(): string
    {
        return $this->rationale;
    }
}
