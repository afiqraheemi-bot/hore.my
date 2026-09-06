<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense\Exception;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\Exception\CorruptPostingIdempotencyMappingException;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;

/**
 * Thrown when `PostingCommandTransactionalExecutor::execute()` reports
 * an idempotent replay for an Expense's underlying Journal, but no
 * matching Expense record can be found (M7).
 *
 * **This is not "first submission" wearing a different name.** An
 * Expense and its Journal are always inserted together, in the same
 * atomic transaction ({@see ExpenseRecordingService}),
 * so a replayed Journal with no corresponding Expense row is an
 * impossible state under normal operation — mirroring
 * {@see CorruptPostingIdempotencyMappingException}'s
 * own reasoning exactly. If this exception is ever observed, something
 * has bypassed `ExpenseRecordingService` (for example, a caller that
 * posted a Journal directly) or corrupted data outside of it; silently
 * treating it as a fresh Expense would hide that fact and risk
 * recording a second Expense for a Journal that already has one. This
 * fails loudly instead.
 */
final class CorruptExpenseRecordException extends \RuntimeException
{
    public static function forUnresolvableExpense(ExpenseId $expenseId, JournalId $journalId): self
    {
        return new self(sprintf(
            'Journal "%s" was reported as an idempotent replay for Expense "%s", but no matching Expense record could be found.',
            $journalId->toString(),
            $expenseId->toString(),
        ));
    }
}
