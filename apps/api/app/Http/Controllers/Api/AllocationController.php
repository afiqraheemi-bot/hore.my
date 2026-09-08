<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Invoicing\Exception\InvoiceNotFoundException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Payments\AllocationService;
use App\Domain\Payments\Exception\AllocationExceedsInvoiceBalanceException;
use App\Domain\Payments\Exception\AllocationExceedsPaymentAmountException;
use App\Domain\Payments\Exception\InvoiceNotIssuedException;
use App\Domain\Payments\Exception\PaymentAllocationNotFoundException;
use App\Domain\Payments\Exception\PaymentNotFoundException;
use App\Domain\Payments\PaymentAllocation;
use App\Domain\Payments\PaymentAllocationId;
use App\Domain\Payments\PaymentId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StoreAllocationRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Invoicing\InvoiceRepository;
use App\Infrastructure\Payments\PaymentAllocationRepository;
use App\Infrastructure\Payments\PaymentRepository;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Allocates an already-recorded Payment against an Issued Invoice, and
 * exposes each Issued Invoice's live-computed outstanding balance
 * (M21) — mirrors {@see MatchController}'s
 * own thin-controller shape (M18), the closest existing precedent for
 * a suggest/confirm-style sub-ledger operation with no ledger effect
 * of its own.
 */
final class AllocationController extends Controller
{
    public function __construct(
        private readonly AllocationService $allocationService,
        private readonly PaymentAllocationRepository $allocationRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentRepository $paymentRepository,
    ) {}

    /**
     * Lists a Payment's own allocations.
     *
     * Checks the Payment exists for this Tenant before returning, even
     * though an empty result would otherwise look identical to "zero
     * allocations" — a nonexistent or malformed `$paymentId` (P1-5,
     * 2026-09-08 audit remediation: found while investigating that
     * finding, though the specific "malformed ID causes a 500" pattern
     * the finding named does not reproduce anywhere in this codebase,
     * since every ID Value Object here is an opaque string, never a
     * native-UUID-typed column that could throw a DB-level cast error)
     * previously returned `200 {"data": []}` indistinguishable from a
     * real Payment with no allocations yet.
     */
    public function index(CurrentTenant $currentTenant, string $paymentId): JsonResponse
    {
        $paymentIdValue = PaymentId::of($paymentId);

        if ($this->paymentRepository->findById($currentTenant->id(), $paymentIdValue) === null) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $allocations = $this->allocationRepository->findByPayment($currentTenant->id(), $paymentIdValue);

        return response()->json(['data' => array_map(fn (PaymentAllocation $a): array => $this->toArray($a), $allocations)]);
    }

    public function store(StoreAllocationRequest $request, CurrentTenant $currentTenant, string $paymentId): JsonResponse
    {
        try {
            $allocation = $this->allocationService->allocate(
                $currentTenant->id(),
                PaymentId::of($paymentId),
                InvoiceId::of($request->string('invoice_id')->toString()),
                Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
            );
        } catch (PaymentNotFoundException|InvoiceNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (
            InvoiceNotIssuedException|
            AllocationExceedsPaymentAmountException|
            AllocationExceedsInvoiceBalanceException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->toArray($allocation), 201);
    }

    public function destroy(Request $request, CurrentTenant $currentTenant, string $allocationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $this->allocationService->deallocate(
                $currentTenant->id(),
                PaymentAllocationId::of($allocationId),
                ActorReference::of($user->id),
            );
        } catch (PaymentAllocationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(null, 204);
    }

    public function outstandingInvoices(CurrentTenant $currentTenant): JsonResponse
    {
        $invoices = array_values(array_filter(
            $this->invoiceRepository->findAllByTenant($currentTenant->id()),
            static fn (Invoice $invoice): bool => $invoice->status() === InvoiceStatus::Issued,
        ));

        $data = array_map(function (Invoice $invoice) use ($currentTenant): array {
            $outstanding = $this->allocationService->outstandingBalanceFor($currentTenant->id(), $invoice->id(), $invoice->totalAmount());

            return [
                'id' => $invoice->id()->toString(),
                'invoice_number' => $invoice->invoiceNumber(),
                'customer_id' => $invoice->customerId()->toString(),
                'total_amount' => $invoice->totalAmount()->toDecimalString(),
                'outstanding_balance' => $outstanding->toDecimalString(),
            ];
        }, $invoices);

        $data = array_values(array_filter($data, static fn (array $row): bool => $row['outstanding_balance'] !== '0.00'));

        return response()->json(['data' => $data]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(PaymentAllocation $allocation): array
    {
        return [
            'id' => $allocation->id()->toString(),
            'payment_id' => $allocation->paymentId()->toString(),
            'invoice_id' => $allocation->invoiceId()->toString(),
            'amount' => $allocation->amount()->toDecimalString(),
        ];
    }
}
