<?php

declare(strict_types=1);

namespace App\Domain\Transactions\OwnerEquity\Exception;

/**
 * Thrown when an Owner Equity Transaction's description is not
 * canonical (M15).
 *
 * A description is required — a Capital Contribution or Drawing with
 * no description at all would defeat the very business-context
 * traceability this milestone exists to preserve (a Posted Journal
 * alone carries only Account/Money/Direction, never *why* the owner
 * moved funds). The maximum length is a defensive engineering bound
 * against pathological input, not a requirement stated anywhere in the
 * SRS.
 */
final class InvalidOwnerEquityTransactionDescriptionException extends \InvalidArgumentException
{
    public static function forEmpty(): self
    {
        return new self('An Owner Equity Transaction description is required and must not be empty.');
    }

    public static function forExceedingMaxLength(int $maxLength): self
    {
        return new self(sprintf('An Owner Equity Transaction description must not exceed %d characters.', $maxLength));
    }
}
