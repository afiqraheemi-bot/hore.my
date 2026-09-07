<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Payments\Exception\InvalidPaymentAllocationIdException;

/**
 * A PaymentAllocation's stable, opaque identifier (M21) — assigned at
 * creation and immutable for its lifetime.
 */
final class PaymentAllocationId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidPaymentAllocationIdException if the value is not
     *                                             a canonical PaymentAllocation identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidPaymentAllocationIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidPaymentAllocationIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidPaymentAllocationIdException::forValue($value);
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
