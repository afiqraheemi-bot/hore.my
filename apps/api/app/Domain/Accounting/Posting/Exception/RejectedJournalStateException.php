<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

use App\Domain\Accounting\Journal\JournalId;

/**
 * Thrown when a Posting Command references an existing Journal whose
 * recorded state is already Posted — Draft-only candidate input
 * (AETS-007 §11, §14 step 3; `POST-018`).
 *
 * A Journal already Posted is terminal (`JRN-005`, `JRN-006`); a
 * Posting Command cannot legitimately reference one as its proposed
 * candidate identity. This is distinct from, and unrelated to, an
 * Account-reference rejection ({@see RejectedAccountReferenceException}) —
 * different subject, different pipeline step.
 */
final class RejectedJournalStateException extends \RuntimeException
{
    public static function forNonDraftJournal(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" is already Posted and cannot be referenced as Draft-only candidate input.',
            $journalId->toString(),
        ));
    }
}
