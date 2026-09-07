<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

/**
 * Thrown when a Reconciliation's period end precedes its period start
 * (M18).
 */
final class InvalidReconciliationPeriodException extends \InvalidArgumentException
{
    public static function forEndBeforeStart(): self
    {
        return new self('A Reconciliation period end must not be before its period start.');
    }
}
