<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\Journal\Exception;

use App\Domain\Accounting\Journal\JournalState;
use App\Infrastructure\Accounting\Journal\JournalPersistenceAdapter;

/**
 * Thrown when a persisted Journal state value does not correspond to
 * one of {@see JournalState}'s two canonical cases.
 *
 * AETS-004 does not lock a persisted representation for Journal state
 * (§9); this exception exists specifically for the minimal,
 * adapter-owned translation {@see JournalPersistenceAdapter}
 * uses, not for a Domain-level concern — a raw database value is never
 * treated as a trusted Journal state until it passes this translation.
 */
final class InvalidPersistedJournalStateException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a persisted representation of a canonical Journal state.',
            $value,
        ));
    }
}
