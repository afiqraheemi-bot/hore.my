<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Money\Exception;

/**
 * Thrown when a Money value's exact MinorUnits amount does not fit
 * within the signed 64-bit integer range the canonical `BIGINT`
 * persistence column requires (AETS-003 §15; ADR-0007's amendment).
 *
 * This is an infrastructure-layer failure, distinct from any
 * Money-domain exception (`App\Domain\Accounting\Money\Exception`):
 * it describes a limit of the chosen persistence representation, not
 * an invariant of Money itself. Raised before any write is attempted —
 * never surfaced as a database-level error.
 */
final class MoneyOutOfPersistenceRangeException extends \RuntimeException
{
    public static function forAmount(string $minorUnits): self
    {
        return new self(sprintf(
            'MinorUnits value "%s" does not fit within the signed 64-bit BIGINT range and cannot be persisted.',
            $minorUnits,
        ));
    }
}
