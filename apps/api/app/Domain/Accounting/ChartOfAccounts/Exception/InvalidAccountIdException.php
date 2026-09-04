<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts\Exception;

/**
 * Thrown when a value is not a canonical Account identifier
 * (AETS-005 §7).
 *
 * AETS-005 treats the identifier's concrete representation (surrogate
 * key, UUID, or otherwise) as an implementation detail — this
 * exception covers only the minimum safe rejections that hold
 * regardless of that representation: empty, whitespace-only,
 * leading/trailing whitespace, a control character, or an
 * adversarially long value.
 */
final class InvalidAccountIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Account identifier.',
            $value,
        ));
    }
}
