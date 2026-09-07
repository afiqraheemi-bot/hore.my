<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Banking\Exception\InvalidBankTransactionIdException;

/**
 * A BankTransaction's stable, opaque identifier (M17) — mirrors
 * {@see BankAccountId} exactly.
 */
final class BankTransactionId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidBankTransactionIdException if the value is not a
     *                                           canonical BankTransaction identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidBankTransactionIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidBankTransactionIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidBankTransactionIdException::forValue($value);
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
