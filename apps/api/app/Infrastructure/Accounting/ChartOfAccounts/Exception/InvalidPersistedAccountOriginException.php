<?php

declare(strict_types=1);

namespace App\Infrastructure\Accounting\ChartOfAccounts\Exception;

use App\Domain\Accounting\ChartOfAccounts\AccountOrigin;
use App\Infrastructure\Accounting\ChartOfAccounts\AccountPersistenceAdapter;

/**
 * Thrown when a persisted Account Origin value does not correspond to
 * one of {@see AccountOrigin}'s
 * two canonical cases.
 *
 * AETS-005 does not lock a persisted representation for Account Origin
 * (§15, §16); this exception exists specifically for the minimal,
 * adapter-owned translation {@see AccountPersistenceAdapter}
 * uses, not for a Domain-level concern — a raw database value is never
 * treated as a trusted Account Origin until it passes this translation.
 */
final class InvalidPersistedAccountOriginException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a persisted representation of a canonical Account Origin.',
            $value,
        ));
    }
}
