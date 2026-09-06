<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity\Exception;

use App\Domain\Accounting\Journal\Exception\InvalidJournalIdException;

/**
 * Thrown when a value is not a canonical Owner Equity Transaction
 * identifier (M15).
 *
 * Mirrors {@see InvalidJournalIdException} exactly: the identifier's
 * concrete representation is an implementation detail — this exception
 * covers only the minimum safe rejections that hold regardless of it
 * (empty, whitespace-only, leading/trailing whitespace, a control
 * character, or an adversarially long value).
 */
final class InvalidOwnerEquityTransactionIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Owner Equity Transaction identifier.',
            $value,
        ));
    }
}
