<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Infrastructure\Accounting\Money\MoneyPersistenceAdapter;

/**
 * Thrown when computing a Reconciliation's implied closing balance
 * would require an intermediate negative Money value (M18) — Money
 * (AETS-003) can currently only ever hold a non-negative exact value;
 * its own sign policy remains deliberately deferred (see
 * {@see MoneyPersistenceAdapter}'s
 * own docblock). This is a real, pre-existing limitation of Money
 * itself, not something this Banking milestone can resolve — an
 * account whose imported transactions would put it into negative
 * balance mid-period (an overdraft) cannot yet be reconciled through
 * this computation; the fix belongs to whichever future AETS-003
 * revision resolves Money's own sign policy.
 */
final class ReconciliationComputationExceedsSupportedRangeException extends \RuntimeException
{
    public static function forNegativeIntermediateBalance(): self
    {
        return new self(
            'This Reconciliation cannot be computed: the imported transactions imply a negative '
            .'intermediate balance, which Money cannot currently represent (Money sign policy is deferred).',
        );
    }
}
