<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Banking\Exception\InvalidReconciliationIdException;

/**
 * A Reconciliation's stable, opaque identifier (M18) — mirrors
 * {@see BankAccountId} exactly.
 */
final class ReconciliationId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidReconciliationIdException if the value is not a
     *                                          canonical Reconciliation identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidReconciliationIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidReconciliationIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidReconciliationIdException::forValue($value);
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
