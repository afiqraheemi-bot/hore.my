<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

/**
 * Thrown when a Quotation line's own fields are not canonical
 * (AETS-016) — an empty/too-long description or a non-positive
 * quantity.
 */
final class InvalidQuotationLineException extends \InvalidArgumentException
{
    public static function forEmptyDescription(): self
    {
        return new self('A Quotation line description is required and must not be empty.');
    }

    public static function forDescriptionExceedingMaxLength(int $maxLength): self
    {
        return new self(sprintf('A Quotation line description must not exceed %d characters.', $maxLength));
    }

    public static function forNonPositiveQuantity(int $quantity): self
    {
        return new self(sprintf('A Quotation line quantity must be a positive integer, got %d.', $quantity));
    }
}
