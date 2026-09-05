<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalId;

/**
 * Thrown when a Journal's `CorrectionType` and correction-chain
 * reference disagree on whether this Journal is a correction at all
 * (M5 architecture decision): an ordinary Journal MUST carry neither;
 * a correction Journal MUST carry both. One present without the other
 * is never a valid state.
 *
 * This is a domain-level defence-in-depth check — the production
 * schema's own `CHECK` constraint (M5) already makes this state
 * unreachable through the application in practice, exactly the same
 * relationship {@see Journal::reconstitute()}'s own docblock already
 * establishes for every other structural invariant it re-validates
 * rather than trusting persisted data.
 */
final class InconsistentCorrectionMetadataException extends \LogicException
{
    public static function forJournalId(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" has an inconsistent correction state: CorrectionType and the correction-chain reference must both be present, or both be absent.',
            $journalId->toString(),
        ));
    }
}
