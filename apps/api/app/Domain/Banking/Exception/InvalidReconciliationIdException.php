<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

/**
 * Thrown when a value is not a canonical Reconciliation identifier
 * (M18).
 */
final class InvalidReconciliationIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical Reconciliation identifier.', $value));
    }
}
