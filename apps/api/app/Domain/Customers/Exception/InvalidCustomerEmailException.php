<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exception;

/**
 * Thrown when a Customer's email is present but not a well-formed
 * address (M19). Email is an optional field — this exception is never
 * thrown for a null/absent email, only a malformed non-empty one.
 */
final class InvalidCustomerEmailException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a well-formed email address.', $value));
    }
}
