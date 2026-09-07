<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Exception\InvalidInvoiceIdException;

/**
 * An Invoice's stable, opaque identifier (M20) — assigned at Draft
 * creation and immutable for its lifetime, never reused or reassigned.
 * Mirrors {@see CustomerId} exactly: an opaque
 * identifier is always supplied by the caller, never self-generated.
 */
final class InvoiceId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidInvoiceIdException if the value is not a
     *                                   canonical Invoice identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidInvoiceIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidInvoiceIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidInvoiceIdException::forValue($value);
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
