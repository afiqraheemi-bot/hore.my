<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense\Exception;

use App\Domain\Accounting\Journal\Exception\InvalidJournalIdException;

/**
 * Thrown when a value is not a canonical Expense identifier (M7).
 *
 * Mirrors {@see InvalidJournalIdException}
 * exactly: the identifier's concrete representation is an
 * implementation detail — this exception covers only the minimum safe
 * rejections that hold regardless of it (empty, whitespace-only,
 * leading/trailing whitespace, a control character, or an
 * adversarially long value).
 */
final class InvalidExpenseIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Value "%s" is not a canonical Expense identifier.',
            $value,
        ));
    }
}
