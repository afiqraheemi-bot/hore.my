<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidOwnerEquityTransactionIdException;

/**
 * An Owner Equity Transaction's stable, opaque identifier (M15) —
 * assigned at creation and immutable for the record's lifetime, never
 * reused or reassigned to a different one.
 *
 * Mirrors {@see JournalId} exactly, for the identical reason: this
 * codebase's established convention is that an opaque identifier is
 * always supplied by the caller, never self-generated (AETS-007 §11).
 *
 * **Distinct from JournalId, deliberately** — mirrors
 * {@see IncomeId}'s own reasoning
 * exactly.
 */
final class OwnerEquityTransactionId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidOwnerEquityTransactionIdException if the value is
     *                                                  not a canonical Owner Equity Transaction identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidOwnerEquityTransactionIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidOwnerEquityTransactionIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidOwnerEquityTransactionIdException::forValue($value);
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
