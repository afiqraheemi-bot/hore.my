<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

/**
 * Thrown when an Invoice line's own fields are not canonical (M20) —
 * an empty/too-long description or a non-positive quantity.
 */
final class InvalidInvoiceLineException extends \InvalidArgumentException
{
    public static function forEmptyDescription(): self
    {
        return new self('An Invoice line description is required and must not be empty.');
    }

    public static function forDescriptionExceedingMaxLength(int $maxLength): self
    {
        return new self(sprintf('An Invoice line description must not exceed %d characters.', $maxLength));
    }

    public static function forNonPositiveQuantity(int $quantity): self
    {
        return new self(sprintf('An Invoice line quantity must be a positive integer, got %d.', $quantity));
    }
}
