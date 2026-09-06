<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Income\Exception;

/**
 * Thrown when an Income's description is not canonical (M9).
 *
 * A description is required — an Income with no description at all
 * would defeat the very business-context traceability this milestone
 * exists to preserve (a Posted Journal alone carries only Account/Money/
 * Direction, never *why* the income was incurred). The maximum length
 * is a defensive engineering bound against pathological input, not a
 * requirement stated anywhere in the SRS.
 */
final class InvalidIncomeDescriptionException extends \InvalidArgumentException
{
    public static function forEmpty(): self
    {
        return new self('An Income description is required and must not be empty.');
    }

    public static function forExceedingMaxLength(int $maxLength): self
    {
        return new self(sprintf('An Income description must not exceed %d characters.', $maxLength));
    }
}
