<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Invoicing\InvoiceId;

/**
 * Thrown when an attempt is made to allocate a Payment against an
 * Invoice that is not yet `Issued` (M21) — a Draft Invoice has no
 * posted Receivable balance to settle.
 */
final class InvoiceNotIssuedException extends \RuntimeException
{
    public static function forInvoice(InvoiceId $invoiceId): self
    {
        return new self(sprintf('Invoice "%s" is not Issued and cannot receive a Payment allocation.', $invoiceId->toString()));
    }
}
