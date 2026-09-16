<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

use App\Domain\Invoicing\InvoiceStatus;

/**
 * A Quotation's lifecycle state (AETS-016 §4): `Draft` -> `Sent` ->
 * `Accepted`/`Rejected`, and `Accepted` -> `Converted`. Unlike
 * {@see InvoiceStatus}, no state here ever
 * carries a ledger effect — see AETS-016 §2.2.
 */
enum QuotationStatus
{
    case Draft;
    case Sent;
    case Accepted;
    case Rejected;
    case Converted;

    /**
     * AETS-016 §4: `Rejected` and `Converted` are terminal — no
     * transition leaves either state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rejected, self::Converted => true,
            default => false,
        };
    }
}
