<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\ReconciliationId;

/**
 * Thrown when a new Reconciliation's period would overlap, in whole or
 * in part, an existing Reconciliation's period for the same Bank
 * Account (AETS-008 §12.4, `BNK-015`) — regardless of that existing
 * Reconciliation's lifecycle state, including `Completed` and a
 * `Completed` Reconciliation currently reopened back to `Draft`. Real
 * bank statements arrive as an ordered, non-overlapping sequence of
 * periods; allowing overlap would make "which Reconciliation does this
 * Bank Transaction's difference belong to" ambiguous.
 */
final class ReconciliationPeriodOverlapException extends \RuntimeException
{
    public static function forOverlap(BankAccountId $bankAccountId, ReconciliationId $existingReconciliationId): self
    {
        return new self(sprintf(
            'BankAccount "%s" already has Reconciliation "%s" covering an overlapping period.',
            $bankAccountId->toString(),
            $existingReconciliationId->toString(),
        ));
    }
}
