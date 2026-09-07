<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exception;

use App\Domain\Payments\PaymentId;

/**
 * Thrown when allocating a Payment would push the sum of that
 * Payment's own allocations above its `amount` (M21) — a Payment can
 * never be allocated more than it actually received.
 */
final class AllocationExceedsPaymentAmountException extends \RuntimeException
{
    public static function forPayment(PaymentId $paymentId, string $unallocatedAmount, string $attemptedAmount): self
    {
        return new self(sprintf(
            'Allocating %s from Payment "%s" would exceed its unallocated amount of %s.',
            $attemptedAmount,
            $paymentId->toString(),
            $unallocatedAmount,
        ));
    }
}
