<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\Transfer\Exception\InvalidTransferIdException;

/**
 * A Transfer's stable, opaque identifier (M14) — assigned at creation
 * and immutable for the Transfer's lifetime, never reused or reassigned
 * to a different Transfer.
 *
 * Mirrors {@see JournalId} exactly, for the identical reason: this
 * codebase's established convention is that an opaque identifier is
 * always supplied by the caller, never self-generated (AETS-007 §11).
 *
 * **Distinct from JournalId, deliberately.** A Transfer (Transactions
 * domain) and the Journal it produces (Accounting Core) are two
 * separate aggregates in two separate domains — mirroring
 * {@see IncomeId}'s own reasoning
 * exactly.
 */
final class TransferId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidTransferIdException if the value is not a
     *                                    canonical Transfer identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidTransferIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidTransferIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidTransferIdException::forValue($value);
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
