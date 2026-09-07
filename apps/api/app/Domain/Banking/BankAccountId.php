<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Banking\Exception\InvalidBankAccountIdException;

/**
 * A BankAccount's stable, opaque identifier (M17) — assigned at
 * creation and immutable for its lifetime, never reused or reassigned.
 * Mirrors {@see JournalId} exactly, for
 * the identical reason: an opaque identifier is always supplied by the
 * caller, never self-generated (AETS-007 §11).
 */
final class BankAccountId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidBankAccountIdException if the value is not a
     *                                       canonical BankAccount identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidBankAccountIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidBankAccountIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidBankAccountIdException::forValue($value);
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
