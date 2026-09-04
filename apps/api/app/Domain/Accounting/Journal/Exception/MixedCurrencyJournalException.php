<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

/**
 * Thrown when a Journal is constructed from Journal Lines that do not
 * all share the same Currency (AETS-004 §12, `JRN-011`) — Money's own
 * cross-currency guard (AETS-003 §6, `MON-006`) forbids comparing or
 * summing Money of different Currency, and multi-currency Journals are
 * out of MVP scope.
 */
final class MixedCurrencyJournalException extends \InvalidArgumentException
{
    public static function forMismatch(): self
    {
        return new self('All Journal Lines within one Journal MUST share the same Currency.');
    }
}
