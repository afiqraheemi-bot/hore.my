<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Invoicing\InvoiceId;

/**
 * Thrown when an attempt is made to edit an Invoice that is no longer
 * `Draft` (M20) — an Issued Invoice is immutable, mirroring a Posted
 * Journal's own append-only nature (AETS-004 §15).
 */
final class InvoiceNotEditableException extends \RuntimeException
{
    public static function forIssuedInvoice(InvoiceId $invoiceId): self
    {
        return new self(sprintf('Invoice "%s" has already been issued and can no longer be edited.', $invoiceId->toString()));
    }
}
