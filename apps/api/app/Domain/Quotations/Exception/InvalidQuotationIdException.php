<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

/**
 * Thrown when a value is not a canonical Quotation identifier
 * (AETS-016).
 */
final class InvalidQuotationIdException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Value "%s" is not a canonical Quotation identifier.', $value));
    }
}
