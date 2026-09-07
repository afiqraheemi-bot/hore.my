<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Payments\Exception\InvalidPaymentIdException;
use App\Domain\Transactions\Income\IncomeId;

/**
 * A Payment's stable, opaque identifier (M21) — assigned at recording
 * and immutable for its lifetime, never reused or reassigned. Mirrors
 * {@see IncomeId} exactly.
 */
final class PaymentId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidPaymentIdException if the value is not a
     *                                   canonical Payment identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidPaymentIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidPaymentIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidPaymentIdException::forValue($value);
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
