<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;

/**
 * Thrown when a Journal's `JournalState` and its `postedAt` presence
 * disagree (M8, AETS-004 §9, §9.1): a Draft Journal must never carry a
 * `postedAt` value, and a Posted Journal must always carry one — one
 * present without the other means posting time was recorded when it
 * should not have been, or was never recorded when it should have
 * been, either way not a state {@see Journal}
 * can silently accept as valid.
 *
 * Mirrors {@see InconsistentCorrectionMetadataException}'s own
 * reasoning exactly, for a different pair of fields.
 */
final class InconsistentPostedAtException extends \LogicException
{
    public static function forJournalId(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" has an inconsistent state: a Draft Journal must have no posted-at time, and a Posted Journal must always have one.',
            $journalId->toString(),
        ));
    }
}
