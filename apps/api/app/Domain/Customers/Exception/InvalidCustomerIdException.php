<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exception;

use App\Domain\Banking\Exception\InvalidBankAccountIdException;

/**
 * Thrown when a value is not a canonical Customer identifier (M19).
 *
 * Mirrors {@see InvalidBankAccountIdException}
 * exactly: the identifier's concrete representation is an
 * implementation detail — this exception covers only the minimum safe
 * rejections that hold regardless of it.
 */
final class InvalidCustomerIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical Customer identifier.', $value));
    }
}
