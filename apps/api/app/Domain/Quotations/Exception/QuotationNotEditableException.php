<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

use App\Domain\Quotations\QuotationId;

/**
 * Thrown when an attempt is made to edit or delete a Quotation that
 * is no longer `Draft` (AETS-016 §6, QUO-002).
 */
final class QuotationNotEditableException extends \RuntimeException
{
    public static function forNonDraftQuotation(QuotationId $quotationId): self
    {
        return new self(sprintf('Quotation "%s" is no longer Draft and can no longer be edited or deleted.', $quotationId->toString()));
    }
}
