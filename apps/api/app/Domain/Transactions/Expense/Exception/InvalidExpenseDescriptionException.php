<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Expense\Exception;

/**
 * Thrown when an Expense's description is not canonical (M7).
 *
 * A description is required — an Expense with no description at all
 * would defeat the very business-context traceability this milestone
 * exists to preserve (a Posted Journal alone carries only Account/Money/
 * Direction, never *why* the expense was incurred). The maximum length
 * is a defensive engineering bound against pathological input, not a
 * requirement stated anywhere in the SRS.
 */
final class InvalidExpenseDescriptionException extends \InvalidArgumentException
{
    public static function forEmpty(): self
    {
        return new self('An Expense description is required and must not be empty.');
    }

    public static function forExceedingMaxLength(int $maxLength): self
    {
        return new self(sprintf('An Expense description must not exceed %d characters.', $maxLength));
    }
}
