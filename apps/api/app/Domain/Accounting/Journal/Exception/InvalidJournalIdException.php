<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Journal\Exception;

/**
 * Thrown when a value is not a canonical Journal identifier
 * (AETS-004 §6, `JRN-003`).
 *
 * AETS-004 treats the identifier's concrete representation (surrogate
 * key, UUID, or otherwise) as an implementation detail — this
 * exception covers only the minimum safe rejections that hold
 * regardless of that representation: empty, whitespace-only,
 * leading/trailing whitespace, a control character, or an
 * adversarially long value.
 */
final class InvalidJournalIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Journal identifier.',
            $value,
        ));
    }
}
