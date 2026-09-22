<?php

declare(strict_types=1);

namespace App\Domain\MyInvois\Exception;

use App\Domain\Invoicing\InvoiceId;

/**
 * Thrown by `UblInvoiceDocumentBuilder` when it cannot build a MyInvois
 * document — a `Draft` Invoice, or a mandatory field named in AETS-013
 * v0.1.0 §6 that is missing (MYI-004). Always names exactly which
 * field or line is missing — the builder never omits a field or
 * substitutes a placeholder to produce a document anyway.
 */
final class MissingMyInvoisDataException extends \RuntimeException
{
    public static function forNonIssuedInvoice(InvoiceId $invoiceId): self
    {
        return new self(sprintf(
            'Cannot build a MyInvois document for Invoice %s — it is not Issued.',
            $invoiceId->toString(),
        ));
    }

    public static function forMissingSupplierField(string $field): self
    {
        return new self(sprintf(
            'Cannot build a MyInvois document — the Business Profile is missing its required "%s" field.',
            $field,
        ));
    }

    public static function forMissingBuyerField(string $field): self
    {
        return new self(sprintf(
            'Cannot build a MyInvois document — the Customer is missing its required "%s" field.',
            $field,
        ));
    }

    public static function forMissingLineDetail(InvoiceId $invoiceId, int $linePosition): self
    {
        return new self(sprintf(
            'Cannot build a MyInvois document for Invoice %s — line %d has no MyInvois detail (tax type, classification code, unit of measure).',
            $invoiceId->toString(),
            $linePosition + 1,
        ));
    }
}
