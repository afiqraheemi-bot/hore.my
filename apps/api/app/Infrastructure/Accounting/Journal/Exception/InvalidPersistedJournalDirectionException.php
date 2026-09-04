<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\JournalDirection;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;

/**
 * Thrown when a persisted Journal Line direction value does not
 * correspond to one of {@see JournalDirection}'s two canonical cases.
 *
 * AETS-004 does not lock a persisted representation for Direction
 * (§8); this exception exists specifically for the minimal,
 * adapter-owned translation {@see JournalPersistenceAdapter}
 * uses, not for a Domain-level concern — a raw database value is never
 * treated as a trusted Direction until it passes this translation.
 */
final class InvalidPersistedJournalDirectionException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a persisted representation of a canonical Journal Line direction.',
            $value,
        ));
    }
}
