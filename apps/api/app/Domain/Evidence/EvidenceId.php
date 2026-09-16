<?php

declare(strict_types=1);

namespace App\Domain\Evidence;

use App\Domain\Banking\BankAccountId;
use App\Domain\Evidence\Exception\InvalidEvidenceIdException;

/**
 * An Evidence record's stable, opaque identifier (AETS-015 §4/§7,
 * `EVI-007`) — mirrors {@see BankAccountId} exactly.
 * Also usable directly as an AETS-010 §8 Evidence Reference: opaque,
 * immutable, exactly comparable.
 */
final class EvidenceId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidEvidenceIdException if the value is not a
     *                                    canonical Evidence identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidEvidenceIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidEvidenceIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidEvidenceIdException::forValue($value);
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
