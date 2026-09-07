<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

/**
 * Thrown when a value is not a canonical ImportBatch identifier (M17).
 */
final class InvalidImportBatchIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical ImportBatch identifier.', $value));
    }
}
