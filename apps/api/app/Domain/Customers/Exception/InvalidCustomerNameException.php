<?php

declare(strict_types=1);

namespace App\Domain\Customers\Exception;

/**
 * Thrown when a Customer's name is not canonical (M19) — empty or
 * exceeding the defensive length bound.
 */
final class InvalidCustomerNameException extends \InvalidArgumentException
{
    public static function forEmpty(): self
    {
        return new self('A Customer name is required and must not be empty.');
    }

    public static function forExceedingMaxLength(int $maxLength): self
    {
        return new self(sprintf('A Customer name must not exceed %d characters.', $maxLength));
    }
}
