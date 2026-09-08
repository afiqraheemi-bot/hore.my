<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Customers\CustomerId;
use App\Domain\Workspace\Exception\InvalidTaskIdException;

/**
 * A Task's stable, opaque identifier (ADR-0009, WTS-001) — assigned at
 * creation and immutable for its lifetime, never reused or reassigned.
 * Mirrors {@see CustomerId} exactly: an opaque
 * identifier is always supplied by the caller, never self-generated.
 */
final class TaskId
{
    private const MAX_LENGTH = 64;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private readonly string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @throws InvalidTaskIdException if the value is not a canonical
     *                                Task identifier.
     */
    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidTaskIdException::forValue($value);
        }

        if (trim($value) !== $value) {
            throw InvalidTaskIdException::forValue($value);
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw InvalidTaskIdException::forValue($value);
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
