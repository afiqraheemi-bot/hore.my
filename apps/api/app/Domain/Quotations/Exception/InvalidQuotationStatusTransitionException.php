<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

use App\Domain\Quotations\QuotationId;
use App\Domain\Quotations\QuotationStatus;

/**
 * Thrown when a Quotation state transition is attempted from a status
 * that does not permit it (AETS-016 §4, QUO-001).
 */
final class InvalidQuotationStatusTransitionException extends \RuntimeException
{
    public static function forTransition(QuotationId $quotationId, QuotationStatus $from, string $attemptedTransition): self
    {
        return new self(sprintf(
            'Quotation "%s" cannot %s from status "%s".',
            $quotationId->toString(),
            $attemptedTransition,
            $from->name,
        ));
    }
}
