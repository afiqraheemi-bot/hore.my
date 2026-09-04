<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

/**
 * Thrown when a Journal is constructed from Journal Lines whose total
 * Debit Money does not exactly equal total Credit Money (AETS-004
 * §12, `JRN-007`) — computed via Money's own exact addition and
 * equality, never native numeric comparison, never a tolerance
 * window. This implementation validates balance at construction
 * (AETS-004 §9 permits assembling and validating a Journal "in one
 * step" as a valid implementation choice), not deferred to a future
 * Posting Command.
 */
final class UnbalancedJournalException extends \InvalidArgumentException
{
    public static function forDifference(): self
    {
        return new self('Total Debit Money MUST equal total Credit Money exactly.');
    }
}
