<?php

declare(strict_types=1);

namespace App\Domain\Accounting\ChartOfAccounts\Exception;

/**
 * Thrown when a value is not a canonical Account Name (AETS-005 §9).
 *
 * AETS-005 requires only that a Name be non-empty and human-readable
 * (`COA-017`) — it introduces no naming taxonomy or localization
 * policy. This exception covers only the minimum safe rejections that
 * hold regardless of any future such policy: empty, whitespace-only,
 * leading/trailing whitespace, a control character, or an
 * adversarially long value. Mirrors
 * {@see InvalidAccountCodeException}'s scope and reasoning for the
 * same class of concern.
 */
final class InvalidAccountNameException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Account Name.',
            $value,
        ));
    }
}
