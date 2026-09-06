<?php

declare(strict_types=1);

namespace App\Domain\Transactions\Transfer\Exception;

/**
 * Thrown when a Transfer's description is not canonical (M14).
 *
 * A description is required — a Transfer with no description at all
 * would defeat the very business-context traceability this milestone
 * exists to preserve (a Posted Journal alone carries only Account/Money/
 * Direction, never *why* funds moved between accounts). The maximum
 * length is a defensive engineering bound against pathological input,
 * not a requirement stated anywhere in the SRS.
 */
final class InvalidTransferDescriptionException extends \InvalidArgumentException
{
    public static function forEmpty(): self
    {
        return new self('A Transfer description is required and must not be empty.');
    }

    public static function forExceedingMaxLength(int $maxLength): self
    {
        return new self(sprintf('A Transfer description must not exceed %d characters.', $maxLength));
    }
}
