<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

/**
 * Thrown when a value is not a canonical Payment identifier (M21).
 */
final class InvalidPaymentIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical Payment identifier.', $value));
    }
}
