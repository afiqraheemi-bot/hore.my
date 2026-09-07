<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

use App\Domain\Banking\ReconciliationId;
use App\Domain\Banking\ReconciliationState;

/**
 * Thrown when a Reconciliation state transition is attempted from a
 * state that does not permit it (M18, SRS §10.4's fixed Draft -> In
 * Review -> Balanced -> Completed sequence, plus Completed -> Draft via
 * an explicit reopen only).
 */
final class InvalidReconciliationStateTransitionException extends \RuntimeException
{
    public static function forTransition(ReconciliationId $id, ReconciliationState $from, string $attemptedTransition): self
    {
        return new self(sprintf(
            'Reconciliation "%s" cannot %s from state %s.',
            $id->toString(),
            $attemptedTransition,
            $from->name,
        ));
    }
}
