<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

use App\Domain\Invoicing\InvoiceId;
use App\Domain\Quotations\Exception\InvalidQuotationIdException;

/**
 * A Quotation's stable, opaque identifier (AETS-016) — assigned at
 * creation and immutable for its lifetime, never reused or
 * reassigned. Mirrors {@see InvoiceId} exactly.
 */
final class QuotationId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidQuotationIdException if the value is not a
     *                                     canonical Quotation identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidQuotationIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidQuotationIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidQuotationIdException::forValue($value);
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
