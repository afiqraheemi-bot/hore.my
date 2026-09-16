<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Banking\ReconciliationId;

/**
 * Thrown when `complete()` is attempted while at least one in-period
 * Bank Transaction has no confirmed Match (AETS-008 §12.1, `BNK-016`)
 * — even if the arithmetic difference is exact zero. Zero difference
 * alone permits offsetting errors (two wrong, canceling mismatches) to
 * pass as a clean reconciliation; every in-period Bank Transaction
 * matched is what real double-entry reconciliation practice means by
 * "reconciled." A Bank Transaction with no corresponding accounting
 * record is a signal to post the missing entry first, not to complete
 * around it.
 */
final class ReconciliationHasUnmatchedTransactionsException extends \RuntimeException
{
    public static function forReconciliation(ReconciliationId $reconciliationId, int $unmatchedCount): self
    {
        return new self(sprintf(
            'Reconciliation "%s" cannot be completed: %d in-period BankTransaction(s) have no confirmed Match.',
            $reconciliationId->toString(),
            $unmatchedCount,
        ));
    }
}
