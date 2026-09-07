<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Invoicing\InvoiceId;

/**
 * Thrown when an attempt is made to issue an Invoice with zero lines
 * (M20) — a Draft Invoice may legitimately have no lines yet while
 * being composed, but issuing one with nothing on it makes no
 * accounting sense (there is no amount to post).
 */
final class EmptyInvoiceCannotBeIssuedException extends \RuntimeException
{
    public static function forInvoice(InvoiceId $invoiceId): self
    {
        return new self(sprintf('Invoice "%s" has no lines and cannot be issued.', $invoiceId->toString()));
    }
}
