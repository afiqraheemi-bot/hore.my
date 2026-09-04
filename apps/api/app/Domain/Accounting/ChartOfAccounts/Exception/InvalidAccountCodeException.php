<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts\Exception;

/**
 * Thrown when a value is not a canonical Account Code (AETS-005 §8).
 *
 * AETS-005 explicitly defers the final Account Code grammar/numbering
 * convention — this exception covers only the minimum safe rejections
 * that are already normatively required: empty, whitespace-only,
 * leading/trailing whitespace, a control character, or an
 * adversarially long value.
 */
final class InvalidAccountCodeException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Account Code.',
            $value,
        ));
    }
}
