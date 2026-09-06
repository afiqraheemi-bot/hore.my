<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Transactions\Expense\Exception\InvalidExpenseIdException;

/**
 * An Expense's stable, opaque identifier (M7) — assigned at creation
 * and immutable for the Expense's lifetime, never reused or reassigned
 * to a different Expense.
 *
 * Mirrors {@see JournalId} exactly, for
 * the identical reason: this codebase's established convention is that
 * an opaque identifier is always supplied by the caller, never
 * self-generated (AETS-007 §11) — how a future application/HTTP layer
 * obtains a fresh, collision-free ExpenseId (a UUID, a ULID, or
 * otherwise) remains deferred, exactly like JournalId's own generation
 * strategy.
 *
 * **Distinct from JournalId, deliberately.** An Expense (Transactions
 * domain) and the Journal it produces (Accounting Core) are two
 * separate aggregates in two separate domains — mirroring the SRS's own
 * data model split between `BusinessTransaction` and `Journal`. This
 * class does not reuse `JournalId` merely because the two share a
 * physical shape; conflating them would make it impossible to ever give
 * Expense its own identity space independent of Accounting Core's.
 */
final class ExpenseId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidExpenseIdException if the value is not a canonical
     *                                   Expense identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidExpenseIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidExpenseIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidExpenseIdException::forValue($value);
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
