<?php

declare(strict_types=1);

namespace App\Domain\Quotations\Exception;

use App\Domain\Quotations\QuotationId;

final class QuotationNotFoundException extends \RuntimeException
{
    public static function forId(QuotationId $quotationId): self
    {
        return new self(sprintf('Quotation "%s" was not found.', $quotationId->toString()));
    }
}
