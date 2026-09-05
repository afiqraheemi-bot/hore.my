<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

/**
 * Thrown when a value is not a canonical Evidence reference
 * (AETS-004 §19, Actor / Source / Evidence Traceability; AETS-010 §8).
 *
 * AETS-010 §8 treats the Evidence reference's concrete physical
 * representation as an implementation decision, mirroring Source's own
 * deferral (AETS-007 §9.1) — this exception covers only the minimum
 * safe rejections that hold regardless of that decision: empty,
 * whitespace-only, leading/trailing whitespace, a control character, or
 * an adversarially long value.
 */
final class InvalidEvidenceReferenceException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Evidence reference.',
            $value,
        ));
    }
}
