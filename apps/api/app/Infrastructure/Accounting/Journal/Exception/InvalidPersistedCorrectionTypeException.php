<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\CorrectionType;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;

/**
 * Thrown when a persisted `correction_type` value does not correspond
 * to one of {@see CorrectionType}'s two canonical cases (M5).
 *
 * AETS-004 does not lock a persisted representation for
 * `CorrectionType`; this exception exists specifically for the
 * minimal, adapter-owned translation {@see JournalPersistenceAdapter}
 * uses, not for a Domain-level concern — a raw database value is never
 * treated as a trusted `CorrectionType` until it passes this
 * translation.
 */
final class InvalidPersistedCorrectionTypeException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a persisted representation of a canonical CorrectionType.',
            $value,
        ));
    }
}
