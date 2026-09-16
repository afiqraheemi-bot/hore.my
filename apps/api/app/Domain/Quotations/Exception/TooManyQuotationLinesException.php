<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

use App\Domain\Invoicing\Exception\TooManyInvoiceLinesException;

/**
 * Thrown when a Quotation is given more lines than the defensive
 * upper bound (AETS-016) — mirrors
 * {@see TooManyInvoiceLinesException}
 * exactly.
 */
final class TooManyQuotationLinesException extends \InvalidArgumentException
{
    public static function forCount(int $count, int $maxCount): self
    {
        return new self(sprintf('A Quotation may have at most %d lines, got %d.', $maxCount, $count));
    }
}
