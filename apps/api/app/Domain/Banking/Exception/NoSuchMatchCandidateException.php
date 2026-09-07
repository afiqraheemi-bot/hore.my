<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Banking\BankTransactionId;
use App\Domain\Banking\BankTransactionMatchSuggester;

/**
 * Thrown when confirming a Match against a `(BankTransactionId,
 * JournalId)` pair that {@see BankTransactionMatchSuggester}
 * does not (or no longer) consider a valid candidate — the Journal
 * might belong to a different Account, a different date/amount, a
 * different Tenant, or already be confirmed-matched to a different
 * BankTransaction. Confirming a Match always re-derives candidacy at
 * confirmation time rather than trusting a client-supplied pair blindly
 * (SRS TRX-004's "preview divalidasi semula" spirit: what was proposed
 * must be re-validated, not merely replayed).
 */
final class NoSuchMatchCandidateException extends \RuntimeException
{
    public static function forPair(BankTransactionId $bankTransactionId, JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" is not a valid match candidate for BankTransaction "%s".',
            $journalId->toString(),
            $bankTransactionId->toString(),
        ));
    }
}
