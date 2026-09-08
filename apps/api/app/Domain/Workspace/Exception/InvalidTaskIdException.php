<?php

declare(strict_types=1);

namespace App\Domain\Workspace\Exception;

use App\Domain\Customers\Exception\InvalidCustomerIdException;

/**
 * Thrown when a value is not a canonical Task identifier (ADR-0009).
 *
 * Mirrors {@see InvalidCustomerIdException}
 * exactly.
 */
final class InvalidTaskIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical Task identifier.', $value));
    }
}
