<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Period\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Period\PeriodClosingService;
use App\Domain\Transactions\Expense\Exception\CorruptExpenseRecordException;

/**
 * Thrown when `PostingCommandTransactionalExecutor::execute()` reports
 * an idempotent replay for a Period-closing Journal, but no matching
 * `period_closures` row can be found (AETS-014).
 *
 * Mirrors {@see CorruptExpenseRecordException}'s
 * own reasoning exactly: a Period closure and its Journal are always
 * inserted together, in the same atomic transaction
 * ({@see PeriodClosingService}), so a
 * replayed closing Journal with no corresponding closure row is an
 * impossible state under normal operation. Fails loudly rather than
 * silently treating it as a fresh closure.
 */
final class CorruptPeriodClosureRecordException extends \RuntimeException
{
    public static function forUnresolvableClosure(JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" was reported as an idempotent replay for a Period closing, but no matching '
            .'period_closures record could be found.',
            $journalId->toString(),
        ));
    }
}
