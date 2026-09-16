<?php

declare(strict_types=1);

namespace App\Domain\Evidence\Exception;

use App\Domain\Banking\Exception\InvalidBankAccountIdException;

/**
 * Thrown when a value is not a canonical Evidence identifier
 * (AETS-015 §4). Mirrors
 * {@see InvalidBankAccountIdException}
 * exactly.
 */
final class InvalidEvidenceIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical Evidence identifier.', $value));
    }
}
