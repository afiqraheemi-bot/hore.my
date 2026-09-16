<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

use App\Domain\Accounting\Money\Money;
use App\Domain\Invoicing\Exception\CorruptInvoiceRecordException;
use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationId;
use App\Domain\Quotations\QuotationStatus;

/**
 * Thrown when reconstituting a Quotation from persisted state and its
 * own data disagrees with itself (AETS-016 §6, QUO-005) — mirrors
 * {@see CorruptInvoiceRecordException}'s
 * own reasoning exactly, applied from this Quotation's own first
 * version rather than added after the fact.
 */
final class CorruptQuotationRecordException extends \RuntimeException
{
    /**
     * Thrown when a persisted `quotations.status` value is not one of
     * {@see QuotationStatus}'s own cases.
     */
    public static function forUnrecognizedStatus(QuotationId $quotationId, string $status): self
    {
        return new self(sprintf(
            'Quotation "%s" has an unrecognized status "%s".',
            $quotationId->toString(),
            $status,
        ));
    }

    public static function forLineAmountMismatch(QuotationId $quotationId, int $lineIndex, Money $expected, Money $actual): self
    {
        return new self(sprintf(
            'Quotation "%s" line %d has lineAmount "%s" but quantity × unitPrice computes to "%s" — this line is corrupt.',
            $quotationId->toString(),
            $lineIndex,
            $actual->toDecimalString(),
            $expected->toDecimalString(),
        ));
    }

    /**
     * Thrown when reconstituting a {@see Quotation} and its persisted
     * `totalAmount` no longer equals the sum of its own lines'
     * `lineAmount`s.
     */
    public static function forTotalAmountMismatch(QuotationId $quotationId, Money $expected, Money $actual): self
    {
        return new self(sprintf(
            'Quotation "%s" has totalAmount "%s" but its own lines sum to "%s" — this Quotation is corrupt.',
            $quotationId->toString(),
            $actual->toDecimalString(),
            $expected->toDecimalString(),
        ));
    }
}
