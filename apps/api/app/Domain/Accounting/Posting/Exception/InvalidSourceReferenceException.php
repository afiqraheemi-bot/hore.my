<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

/**
 * Thrown when a value is not a canonical Source reference
 * (AETS-004 §19, Actor / Source / Evidence Traceability; AETS-007 §9.1).
 *
 * AETS-007 §9.1 treats the Source reference's concrete physical
 * representation as an implementation decision — this exception
 * covers only the minimum safe rejections that hold regardless of
 * that decision: empty, whitespace-only, leading/trailing whitespace,
 * a control character, or an adversarially long value.
 */
final class InvalidSourceReferenceException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Source reference.',
            $value,
        ));
    }
}
