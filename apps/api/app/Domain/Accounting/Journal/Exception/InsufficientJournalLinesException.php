<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

/**
 * Thrown when a Journal is constructed with fewer than two Journal
 * Lines (AETS-004 §7, `JRN-002`) — a single-sided entry cannot
 * balance, and this requirement is final for the current
 * specification baseline (AETS-004 §22).
 */
final class InsufficientJournalLinesException extends \InvalidArgumentException
{
    public static function forCount(int $count): self
    {
        return new self(sprintf(
            'A Journal MUST contain at least two Journal Lines; %d given.',
            $count,
        ));
    }
}
