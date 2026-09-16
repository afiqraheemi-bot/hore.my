<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

use App\Domain\Quotations\QuotationId;

/**
 * Thrown when an attempt is made to send a Quotation with zero lines
 * (AETS-016 §6, QUO-004) — a Draft Quotation may legitimately have no
 * lines yet while being composed, but sending one with nothing on it
 * makes no sense to quote a price for.
 */
final class EmptyQuotationCannotBeSentException extends \RuntimeException
{
    public static function forQuotation(QuotationId $quotationId): self
    {
        return new self(sprintf('Quotation "%s" has no lines and cannot be sent.', $quotationId->toString()));
    }
}
