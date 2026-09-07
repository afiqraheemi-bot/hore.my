<?php

declare(strict_types=1);

namespace App\Domain\Banking\Exception;

/**
 * Thrown when a value is not a canonical BankTransaction identifier
 * (M17).
 */
final class InvalidBankTransactionIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical BankTransaction identifier.', $value));
    }
}
