<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Money\Exception;

/**
 * Thrown when a value is not a canonical non-negative integer numeral
 * (AETS-003 §8; ATS-003 MON-T016).
 */
final class InvalidMinorUnitsException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical non-negative integer numeral.',
            $value,
        ));
    }
}
