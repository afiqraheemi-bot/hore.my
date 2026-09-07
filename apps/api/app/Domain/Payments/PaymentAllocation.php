<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\Money\Money;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Payments\Exception\InvalidAllocationAmountException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The link between a Payment and an Invoice it (fully or partially)
 * settles (M21) — pure sub-ledger bookkeeping with no ledger effect of
 * its own (see the owning migration's own docblock). The two
 * cross-row invariants ("never exceed the Invoice's balance", "never
 * exceed the Payment's own amount") are
 * {@see AllocationService}'s concern, not this class's own — mirrors
 * how Account Type validation lives in a separate Validator, not in
 * Account itself, throughout this codebase.
 */
final class PaymentAllocation
{
    private function __construct(
        private readonly PaymentAllocationId $id,
        private readonly TenantId $tenantId,
        private readonly PaymentId $paymentId,
        private readonly InvoiceId $invoiceId,
        private readonly Money $amount,
    ) {}

    /**
     * @throws InvalidAllocationAmountException if `$amount` is not
     *                                          strictly positive.
     */
    public static function create(
        PaymentAllocationId $id,
        TenantId $tenantId,
        PaymentId $paymentId,
        InvoiceId $invoiceId,
        Money $amount,
    ): self {
        if ($amount->toMinorUnits()->toString() === '0') {
            throw InvalidAllocationAmountException::forNonPositiveAmount();
        }

        return new self($id, $tenantId, $paymentId, $invoiceId, $amount);
    }

    /**
     * Reconstruct an already-persisted PaymentAllocation from storage
     * — no validation beyond each field's own bound.
     */
    public static function reconstitute(
        PaymentAllocationId $id,
        TenantId $tenantId,
        PaymentId $paymentId,
        InvoiceId $invoiceId,
        Money $amount,
    ): self {
        return new self($id, $tenantId, $paymentId, $invoiceId, $amount);
    }

    public function id(): PaymentAllocationId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function paymentId(): PaymentId
    {
        return $this->paymentId;
    }

    public function invoiceId(): InvoiceId
    {
        return $this->invoiceId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
