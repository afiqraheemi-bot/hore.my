<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Invoicing\Exception\InvoiceNotFoundException;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payments\Exception\AllocationExceedsInvoiceBalanceException;
use App\Domain\Payments\Exception\AllocationExceedsPaymentAmountException;
use App\Domain\Payments\Exception\InvoiceNotIssuedException;
use App\Domain\Payments\Exception\PaymentAllocationNotFoundException;
use App\Domain\Payments\Exception\PaymentNotFoundException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Invoicing\InvoiceRepository;
use App\Infrastructure\Payments\PaymentAllocationRepository;
use App\Infrastructure\Payments\PaymentRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Allocates an already-recorded Payment against an Issued Invoice
 * (M21) — pure sub-ledger bookkeeping, no ledger effect of its own
 * (see `payment_allocations`' own migration docblock). Enforces the
 * two named invariants Master Context §10 locks: an Invoice's own
 * allocations must never exceed its `total_amount`, and a Payment's
 * own allocations must never exceed its `amount`.
 *
 * **Row-level locking against the concurrent-allocation race.**
 * Both invariant checks read a cross-row `SUM(...)` before deciding
 * whether a new allocation fits — without locking, two concurrent
 * `allocate()` calls against the same Payment or Invoice could each
 * read the same pre-allocation sum and both proceed, together
 * exceeding the limit neither alone would have. `allocate()` therefore
 * opens a transaction and takes `SELECT ... FOR UPDATE` locks on the
 * specific Payment and Invoice rows before computing either sum,
 * serializing concurrent attempts against the same Payment or Invoice
 * without blocking unrelated ones.
 */
final class AllocationService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PaymentRepository $paymentRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentAllocationRepository $allocationRepository,
    ) {}

    /**
     * @throws PaymentNotFoundException if no Payment
     *                                  exists for `$paymentId` under this Tenant.
     * @throws InvoiceNotFoundException if no Invoice
     *                                  exists for `$invoiceId` under this Tenant.
     * @throws InvoiceNotIssuedException if the Invoice
     *                                   is not `Issued`.
     * @throws AllocationExceedsPaymentAmountException if `$amount`
     *                                                 would exceed the Payment's own unallocated amount.
     * @throws AllocationExceedsInvoiceBalanceException if `$amount`
     *                                                  would exceed the Invoice's own outstanding balance.
     */
    public function allocate(TenantId $tenantId, PaymentId $paymentId, InvoiceId $invoiceId, Money $amount): PaymentAllocation
    {
        return $this->connection->transaction(function () use ($tenantId, $paymentId, $invoiceId, $amount): PaymentAllocation {
            $this->connection->table('payments')
                ->where('tenant_id', $tenantId->toString())
                ->where('id', $paymentId->toString())
                ->lockForUpdate()
                ->first();

            $this->connection->table('invoices')
                ->where('tenant_id', $tenantId->toString())
                ->where('id', $invoiceId->toString())
                ->lockForUpdate()
                ->first();

            $payment = $this->paymentRepository->findById($tenantId, $paymentId);
            if ($payment === null) {
                throw PaymentNotFoundException::forId($paymentId);
            }

            $invoice = $this->invoiceRepository->findById($tenantId, $invoiceId);
            if ($invoice === null) {
                throw InvoiceNotFoundException::forId($invoiceId);
            }
            if ($invoice->status() !== InvoiceStatus::Issued) {
                throw InvoiceNotIssuedException::forInvoice($invoiceId);
            }

            $currency = $payment->amount()->currency();

            $allocatedToPayment = $this->allocationRepository->sumForPayment($tenantId, $paymentId, $currency);
            $unallocated = $payment->amount()->subtract($allocatedToPayment);
            if ($amount->compareTo($unallocated) > 0) {
                throw AllocationExceedsPaymentAmountException::forPayment($paymentId, $unallocated->toDecimalString(), $amount->toDecimalString());
            }

            $allocatedToInvoice = $this->allocationRepository->sumForInvoice($tenantId, $invoiceId, $currency);
            $outstanding = $invoice->totalAmount()->subtract($allocatedToInvoice);
            if ($amount->compareTo($outstanding) > 0) {
                throw AllocationExceedsInvoiceBalanceException::forInvoice($invoiceId, $outstanding->toDecimalString(), $amount->toDecimalString());
            }

            $allocation = PaymentAllocation::create(
                PaymentAllocationId::of((string) Str::uuid()),
                $tenantId,
                $paymentId,
                $invoiceId,
                $amount,
            );

            $this->allocationRepository->save($allocation);

            return $allocation;
        });
    }

    /**
     * Removes an allocation's effect (freeing both the Invoice's
     * outstanding balance and the Payment's unallocated amount by the
     * same amount) without physically deleting the row — `$actor` is
     * recorded alongside the deletion timestamp as this operation's
     * own audit trail (P1-3, 2026-09-08 audit remediation; see
     * {@see PaymentAllocationRepository::softDelete()}).
     *
     * **Concurrent deallocation cannot overwrite the winning actor**
     * (P1-3 follow-up, 2026-09-11). The `findById()` check below is a
     * fast, friendly rejection for the ordinary sequential case — it
     * is not, by itself, what makes this race-safe. That guarantee
     * comes from `softDelete()`'s own atomic `UPDATE ... WHERE
     * deleted_at IS NULL`: of two concurrent callers, only the one
     * that actually flips the row wins; the other's `softDelete()`
     * call returns `false`, which this method treats identically to a
     * `findById()` miss — never as a silent no-op success, and never
     * allowed to overwrite the winner's `deleted_by_actor`.
     *
     * @throws PaymentAllocationNotFoundException if no allocation
     *                                            exists for `$allocationId` under this Tenant, if it was already
     *                                            deallocated before this call started, or if a concurrent call
     *                                            deallocated it first — all three are reported identically, by
     *                                            design: from this call's own point of view, none of them
     *                                            succeeded in deallocating anything.
     */
    public function deallocate(TenantId $tenantId, PaymentAllocationId $allocationId, ActorReference $actor): void
    {
        $allocation = $this->allocationRepository->findById($tenantId, $allocationId);

        if ($allocation === null) {
            throw PaymentAllocationNotFoundException::forId($allocationId);
        }

        $deleted = $this->allocationRepository->softDelete($tenantId, $allocationId, $actor);

        if (! $deleted) {
            throw PaymentAllocationNotFoundException::forId($allocationId);
        }
    }

    public function outstandingBalanceFor(TenantId $tenantId, InvoiceId $invoiceId, Money $totalAmount): Money
    {
        $allocated = $this->allocationRepository->sumForInvoice($tenantId, $invoiceId, $totalAmount->currency());

        return $totalAmount->subtract($allocated);
    }
}
