<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceStatus;

/**
 * Thrown when an Invoice's `issue()` transition is attempted from a
 * status other than `Draft` (M20) — e.g. an already-Issued Invoice.
 */
final class InvalidInvoiceStatusTransitionException extends \RuntimeException
{
    public static function forNonDraftInvoice(InvoiceId $invoiceId, InvoiceStatus $currentStatus): self
    {
        return new self(sprintf(
            'Invoice "%s" cannot be issued from status "%s" — only a Draft Invoice can be issued.',
            $invoiceId->toString(),
            $currentStatus->name,
        ));
    }
}
