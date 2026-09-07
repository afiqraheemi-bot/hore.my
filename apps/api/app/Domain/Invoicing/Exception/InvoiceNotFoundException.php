<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exception;

use App\Domain\Invoicing\InvoiceId;

final class InvoiceNotFoundException extends \RuntimeException
{
    public static function forId(InvoiceId $invoiceId): self
    {
        return new self(sprintf('Invoice "%s" was not found.', $invoiceId->toString()));
    }
}
