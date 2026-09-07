<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

/**
 * Thrown when a BankAccount's bank name is not canonical (M17) — empty
 * or exceeding the defensive length bound.
 */
final class InvalidBankAccountNameException extends \InvalidArgumentException
{
    public static function forEmpty(): self
    {
        return new self('A BankAccount bank name is required and must not be empty.');
    }

    public static function forExceedingMaxLength(int $maxLength): self
    {
        return new self(sprintf('A BankAccount bank name must not exceed %d characters.', $maxLength));
    }
}
