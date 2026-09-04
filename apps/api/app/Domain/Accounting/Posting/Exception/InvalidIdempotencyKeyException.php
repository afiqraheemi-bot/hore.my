<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Posting\Exception;

/**
 * Thrown when a value is not a canonical Idempotency Key
 * (AETS-001, Idempotency Key; AETS-007 §6.1).
 *
 * AETS-004 §14 and AETS-007 §6.1 both treat the Idempotency Key's
 * concrete derivation (hashing scheme, UUID, ULID, or otherwise) as a
 * later implementation decision — this exception covers only the
 * minimum safe rejections that hold regardless of that decision:
 * empty, whitespace-only, leading/trailing whitespace, a control
 * character, or an adversarially long value.
 */
final class InvalidIdempotencyKeyException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Idempotency Key.',
            $value,
        ));
    }
}
