<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

/**
 * Thrown when a value is not a canonical Source Fingerprint
 * (AETS-001, Source Fingerprint; AETS-007 §6.2).
 *
 * AETS-001 and AETS-007 §6.2 both treat the Source Fingerprint's
 * concrete derivation (hashing scheme, content-vs-origin basis, or
 * otherwise) as a later implementation decision — this exception
 * covers only the minimum safe rejections that hold regardless of
 * that decision: empty, whitespace-only, leading/trailing whitespace,
 * a control character, or an adversarially long value.
 */
final class InvalidSourceFingerprintException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Source Fingerprint.',
            $value,
        ));
    }
}
