<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Invoicing\InvoiceId;

/**
 * Thrown when allocating a Payment to an Invoice would push the sum of
 * that Invoice's own allocations above its `total_amount` (M21) —
 * enforces "Allocation bayaran tidak melebihi baki invois" (Master
 * Context §10, a named, locked invariant).
 */
final class AllocationExceedsInvoiceBalanceException extends \RuntimeException
{
    public static function forInvoice(InvoiceId $invoiceId, string $outstandingBalance, string $attemptedAmount): self
    {
        return new self(sprintf(
            'Allocating %s to Invoice "%s" would exceed its outstanding balance of %s.',
            $attemptedAmount,
            $invoiceId->toString(),
            $outstandingBalance,
        ));
    }
}
