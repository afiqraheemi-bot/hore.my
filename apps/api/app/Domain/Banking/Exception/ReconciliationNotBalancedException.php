<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Banking\ReconciliationDifference;
use App\Domain\Banking\ReconciliationId;

/**
 * Thrown when marking a Reconciliation Balanced while its
 * {@see ReconciliationDifference} is not zero (M18, SRS BNK-006:
 * "Perbezaan selesai RM0.00" — a completed reconciliation's difference
 * is RM0.00).
 */
final class ReconciliationNotBalancedException extends \RuntimeException
{
    public static function forDifference(ReconciliationId $id, ReconciliationDifference $difference): self
    {
        $sign = $difference->sign();

        return new self(sprintf(
            'Reconciliation "%s" is not balanced: a difference of %s (%s) remains.',
            $id->toString(),
            $difference->amount()->toDecimalString(),
            $sign === null ? 'none' : $sign->name,
        ));
    }
}
