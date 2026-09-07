<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Banking\BankAccountId;
use App\Domain\Customers\Exception\InvalidCustomerIdException;

/**
 * A Customer's stable, opaque identifier (M19) — assigned at creation
 * and immutable for its lifetime, never reused or reassigned. Mirrors
 * {@see BankAccountId} exactly: an opaque
 * identifier is always supplied by the caller, never self-generated.
 */
final class CustomerId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidCustomerIdException if the value is not a
     *                                    canonical Customer identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidCustomerIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidCustomerIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidCustomerIdException::forValue($value);
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
