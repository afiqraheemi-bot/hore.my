<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/**
 * An Invoice's lifecycle state (M20) — exactly two states for this
 * milestone: `Draft` (mutable, no ledger effect) and `Issued`
 * (immutable, produced a Posted Journal). Cancelling an Issued Invoice
 * is a future milestone's own transition (it requires Journal
 * reversal, AETS §M5's own mechanism) — deliberately not modelled
 * here.
 */
enum InvoiceStatus
{
    case Draft;
    case Issued;
}
