<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Customers\Exception\InvalidCustomerIdException;

/**
 * Thrown when a value is not a canonical Invoice identifier (M20).
 *
 * Mirrors {@see InvalidCustomerIdException}
 * exactly: the identifier's concrete representation is an
 * implementation detail — this exception covers only the minimum safe
 * rejections that hold regardless of it.
 */
final class InvalidInvoiceIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical Invoice identifier.', $value));
    }
}
