<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\Exception\RejectedClosedPeriodPostingException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Exception\EmptyInvoiceCannotBeIssuedException;
use App\Domain\Invoicing\Exception\InvalidInvoiceDueDateException;
use App\Domain\Invoicing\Exception\InvalidInvoiceLineException;
use App\Domain\Invoicing\Exception\InvalidInvoiceStatusTransitionException;
use App\Domain\Invoicing\Exception\InvalidReceivableAccountTypeException;
use App\Domain\Invoicing\Exception\InvalidRevenueAccountTypeException;
use App\Domain\Invoicing\Exception\InvoiceNotEditableException;
use App\Domain\Invoicing\Exception\InvoiceNotFoundException;
use App\Domain\Invoicing\Exception\TooManyInvoiceLinesException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceAccountTypeValidator;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\MyInvois\Exception\InvalidMyInvoisLineDetailException;
use App\Domain\MyInvois\MyInvoisLineDetail;
use App\Domain\MyInvois\MyInvoisTaxType;
use App\Domain\Payments\AllocationService;
use App\Domain\Payments\Payment;
use App\Domain\Payments\PaymentAllocation;
use App\Domain\Shared\Tenancy\TenantId;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\IssueInvoiceRequest;
use App\Http\Requests\Invoicing\StoreInvoiceRequest;
use App\Http\Requests\Invoicing\UpdateInvoiceRequest;
use App\Http\Support\CurrentTenant;
use App\Http\Support\DeterministicIdempotentId;
use App\Http\Support\DocumentPartyFormatter;
use App\Http\Support\DocumentPdfBuilder;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Invoicing\InvoiceRepository;
use App\Infrastructure\MyInvois\MyInvoisInvoiceLineDetailRepository;
use App\Infrastructure\Payments\PaymentAllocationRepository;
use App\Infrastructure\Payments\PaymentRepository;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates, edits, deletes, lists, and issues a Tenant's own Invoices
 * (M20, Modul 7 phase 2). A Draft Invoice never touches the ledger —
 * only {@see issue()} does, via {@see InvoiceIssuingService}, mirroring
 * every other Transactions module's own HTTP contract (an
 * `Idempotency-Key` header required on the one endpoint that posts).
 *
 * **{@see pdf()} (AETS-017, added 2026-09-16)** renders a minimal,
 * functional PDF of this Invoice, Draft or Issued alike.
 */
final class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly InvoiceAccountTypeValidator $accountTypeValidator,
        private readonly InvoiceIssuingService $issuingService,
        private readonly AllocationService $allocationService,
        private readonly PaymentAllocationRepository $allocationRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly MyInvoisInvoiceLineDetailRepository $myInvoisLineDetailRepository,
    ) {}

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $invoices = $this->invoiceRepository->findAllByTenant($currentTenant->id());

        return response()->json(['data' => array_map(fn (Invoice $i): array => $this->toArray($i), $invoices)]);
    }

    /**
     * Unlike {@see index()}, also resolves payment history and the
     * live outstanding balance for an Issued Invoice (M21 data,
     * previously unexposed anywhere) — deliberately not folded into
     * the shared {@see toArray()} used by every other action here, so
     * listing a Tenant's Invoices never pays for N extra allocation
     * queries it never asked for.
     */
    public function show(CurrentTenant $currentTenant, string $invoiceId): JsonResponse
    {
        $invoice = $this->invoiceRepository->findById($currentTenant->id(), InvoiceId::of($invoiceId));

        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $data = $this->toArray($invoice);
        $data['lines'] = $this->withMyInvoisLineDetails($currentTenant->id(), $invoice);

        if ($invoice->status() === InvoiceStatus::Issued) {
            $allocations = $this->allocationRepository->findByInvoice($currentTenant->id(), $invoice->id());

            $data['outstanding_balance'] = $this->allocationService
                ->outstandingBalanceFor($currentTenant->id(), $invoice->id(), $invoice->totalAmount())
                ->toDecimalString();
            $data['payments'] = array_values(array_filter(array_map(
                function (PaymentAllocation $allocation) use ($currentTenant): ?array {
                    $payment = $this->paymentRepository->findById($currentTenant->id(), $allocation->paymentId());

                    if ($payment === null) {
                        return null;
                    }

                    return [
                        'allocation_id' => $allocation->id()->toString(),
                        'payment_id' => $payment->id()->toString(),
                        'amount' => $allocation->amount()->toDecimalString(),
                        'payment_date' => $payment->paymentDate()->format('Y-m-d'),
                        'reference' => $payment->reference(),
                    ];
                },
                $allocations,
            )));
        }

        return response()->json($data);
    }

    public function store(StoreInvoiceRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $customerId = CustomerId::of($request->string('customer_id')->toString());

        if ($this->customerRepository->findById($currentTenant->id(), $customerId) === null) {
            return response()->json(['message' => 'Customer not found.'], 422);
        }

        $receivableAccountId = AccountId::of($request->string('receivable_account_id')->toString());
        $revenueAccountId = AccountId::of($request->string('revenue_account_id')->toString());
        $lines = $request->array('lines');

        try {
            $this->accountTypeValidator->validate($currentTenant->id(), $receivableAccountId, $revenueAccountId);

            $invoice = Invoice::draft(
                InvoiceId::of((string) Str::uuid()),
                $currentTenant->id(),
                $customerId,
                new \DateTimeImmutable($request->string('due_date')->toString()),
                $receivableAccountId,
                $revenueAccountId,
                $this->linesFromRequest($lines),
                Currency::of('MYR'),
            );
            $lineDetails = $this->myInvoisLineDetailsFromRequest($lines);
        } catch (
            RejectedAccountReferenceException|
            InvalidReceivableAccountTypeException|
            InvalidRevenueAccountTypeException|
            InvalidInvoiceLineException|
            TooManyInvoiceLinesException|
            InvalidMyInvoisLineDetailException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invoiceRepository->save($invoice);
        $this->myInvoisLineDetailRepository->replaceForInvoice($currentTenant->id(), $invoice->id(), $lineDetails);

        $data = $this->toArray($invoice);
        $data['lines'] = $this->withMyInvoisLineDetails($currentTenant->id(), $invoice);

        return response()->json($data, 201);
    }

    public function update(UpdateInvoiceRequest $request, CurrentTenant $currentTenant, string $invoiceId): JsonResponse
    {
        $existing = $this->invoiceRepository->findById($currentTenant->id(), InvoiceId::of($invoiceId));

        if ($existing === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $receivableAccountId = AccountId::of($request->string('receivable_account_id')->toString());
        $revenueAccountId = AccountId::of($request->string('revenue_account_id')->toString());
        $lines = $request->array('lines');

        try {
            $this->accountTypeValidator->validate($currentTenant->id(), $receivableAccountId, $revenueAccountId);

            $invoice = $existing->update(
                new \DateTimeImmutable($request->string('due_date')->toString()),
                $receivableAccountId,
                $revenueAccountId,
                $this->linesFromRequest($lines),
            );
            $lineDetails = $this->myInvoisLineDetailsFromRequest($lines);
        } catch (
            RejectedAccountReferenceException|
            InvalidReceivableAccountTypeException|
            InvalidRevenueAccountTypeException|
            InvalidInvoiceLineException|
            TooManyInvoiceLinesException|
            InvalidMyInvoisLineDetailException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InvoiceNotEditableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $this->invoiceRepository->update($invoice);
        $this->myInvoisLineDetailRepository->replaceForInvoice($currentTenant->id(), $invoice->id(), $lineDetails);

        $data = $this->toArray($invoice);
        $data['lines'] = $this->withMyInvoisLineDetails($currentTenant->id(), $invoice);

        return response()->json($data);
    }

    public function destroy(CurrentTenant $currentTenant, string $invoiceId): JsonResponse
    {
        $id = InvoiceId::of($invoiceId);
        $invoice = $this->invoiceRepository->findById($currentTenant->id(), $id);

        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status() !== InvoiceStatus::Draft) {
            return response()->json(['message' => 'Only a Draft Invoice can be deleted.'], 409);
        }

        $this->invoiceRepository->delete($currentTenant->id(), $id);

        return response()->json(null, 204);
    }

    public function issue(IssueInvoiceRequest $request, CurrentTenant $currentTenant, string $invoiceId): JsonResponse
    {
        $idempotencyKeyHeader = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKeyHeader) || $idempotencyKeyHeader === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $idempotencyKey = IdempotencyKey::of($idempotencyKeyHeader);
        $journalId = JournalId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'journal'));

        try {
            $result = $this->issuingService->issue(
                $currentTenant->id(),
                InvoiceId::of($invoiceId),
                $journalId,
                $idempotencyKey,
                ActorReference::of($user->id),
                new \DateTimeImmutable($request->string('issue_date')->toString()),
            );
        } catch (InvoiceNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (
            InvalidInvoiceStatusTransitionException|
            EmptyInvoiceCannotBeIssuedException|
            InvalidInvoiceDueDateException|
            RejectedAccountReferenceException|
            RejectedClosedPeriodPostingException|
            RejectedConflictingIdempotencyReuseException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice = $result->invoice();

        return response()->json($this->toArray($invoice), $result->isNewlyIssued() ? 201 : 200);
    }

    /**
     * AETS-017: a minimal, functional PDF rendering of this Invoice —
     * available for a Draft (labelled "DRAFT," a preview) or an Issued
     * Invoice alike, since a Tenant composing a Draft may reasonably
     * want to preview it before issuing.
     */
    public function pdf(CurrentTenant $currentTenant, string $invoiceId): Response|JsonResponse
    {
        $invoice = $this->invoiceRepository->findById($currentTenant->id(), InvoiceId::of($invoiceId));

        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $customer = $this->customerRepository->findById($currentTenant->id(), $invoice->customerId());
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());

        return DocumentPdfBuilder::build(
            sprintf('%s.pdf', $invoice->invoiceNumber() ?? 'invoice-draft-'.substr($invoice->id()->toString(), 0, 8)),
            'pdf.document',
            [
                'documentTypeLabel' => 'INVOICE',
                'documentNumber' => $invoice->invoiceNumber(),
                'statusLabel' => $invoice->status()->name,
                'issueDate' => $invoice->issueDate()?->format('Y-m-d'),
                'secondaryDateLabel' => 'Due Date',
                'secondaryDate' => $invoice->dueDate()->format('Y-m-d'),
                'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
                'buyer' => DocumentPartyFormatter::buyerFromCustomer($customer),
                'lines' => array_map(static fn (InvoiceLine $line): array => [
                    'description' => $line->description(),
                    'quantity' => $line->quantity(),
                    'unit_price' => $line->unitPrice()->toDecimalString(),
                    'line_amount' => $line->lineAmount()->toDecimalString(),
                ], $invoice->lines()),
                'totalAmount' => $invoice->totalAmount()->toDecimalString(),
                'currency' => $invoice->totalAmount()->currency()->identifier(),
            ],
        );
    }

    /**
     * @param  array<int, array{description: string, quantity: int, unit_price: string}>  $lines
     * @return list<InvoiceLine>
     */
    private function linesFromRequest(array $lines): array
    {
        return array_values(array_map(fn (array $line): InvoiceLine => InvoiceLine::of(
            $line['description'],
            $line['quantity'],
            Money::fromDecimalString($line['unit_price'], Currency::of('MYR')),
        ), $lines));
    }

    /**
     * Same request array {@see linesFromRequest()} reads.
     *
     * @param  array<int, array{description: string, quantity: int, unit_price: string, myinvois_tax_type?: string|null, myinvois_tax_rate?: string|int|float|null, myinvois_tax_amount?: string|null, myinvois_classification_code?: string|null, myinvois_unit_of_measure?: string|null}>  $lines
     * @return array<int, MyInvoisLineDetail|null> Keyed by 0-based line position — `null` where a line supplies no MyInvois data at all.
     *
     * @throws InvalidMyInvoisLineDetailException if a line supplies some but not all of the five MyInvois fields (AETS-013 v0.1.0 §6.1).
     */
    private function myInvoisLineDetailsFromRequest(array $lines): array
    {
        return array_values(array_map(function (array $line): ?MyInvoisLineDetail {
            $taxType = $line['myinvois_tax_type'] ?? null;
            $taxRate = $line['myinvois_tax_rate'] ?? null;
            $taxAmount = $line['myinvois_tax_amount'] ?? null;
            $classificationCode = $line['myinvois_classification_code'] ?? null;
            $unitOfMeasure = $line['myinvois_unit_of_measure'] ?? null;

            $presentCount = count(array_filter(
                [$taxType, $taxRate, $taxAmount, $classificationCode, $unitOfMeasure],
                static fn (mixed $value): bool => $value !== null && $value !== '',
            ));

            if ($presentCount === 0) {
                return null;
            }

            if (
                $presentCount < 5
                || ! is_string($taxType)
                || ! is_string($taxAmount)
                || ! is_string($classificationCode)
                || ! is_string($unitOfMeasure)
            ) {
                throw InvalidMyInvoisLineDetailException::forPartiallyFilledLine();
            }

            return MyInvoisLineDetail::of(
                MyInvoisTaxType::from($taxType),
                (string) $taxRate,
                Money::fromDecimalString($taxAmount, Currency::of('MYR')),
                $classificationCode,
                $unitOfMeasure,
            );
        }, $lines));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function withMyInvoisLineDetails(TenantId $tenantId, Invoice $invoice): array
    {
        $details = $this->myInvoisLineDetailRepository->findForInvoice(
            $tenantId,
            $invoice->id(),
            $invoice->totalAmount()->currency()->identifier(),
        );

        $lines = [];
        foreach ($invoice->lines() as $position => $line) {
            $detail = $details[$position] ?? null;

            $lines[] = [
                'description' => $line->description(),
                'quantity' => $line->quantity(),
                'unit_price' => $line->unitPrice()->toDecimalString(),
                'line_amount' => $line->lineAmount()->toDecimalString(),
                'myinvois_tax_type' => $detail?->taxType()->value,
                'myinvois_tax_rate' => $detail?->taxRate(),
                'myinvois_tax_amount' => $detail?->taxAmount()->toDecimalString(),
                'myinvois_classification_code' => $detail?->classificationCode(),
                'myinvois_unit_of_measure' => $detail?->unitOfMeasure(),
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id()->toString(),
            'customer_id' => $invoice->customerId()->toString(),
            'invoice_number' => $invoice->invoiceNumber(),
            'status' => $invoice->status()->name,
            'issue_date' => $invoice->issueDate()?->format('Y-m-d'),
            'due_date' => $invoice->dueDate()->format('Y-m-d'),
            'receivable_account_id' => $invoice->receivableAccountId()->toString(),
            'revenue_account_id' => $invoice->revenueAccountId()->toString(),
            'journal_id' => $invoice->journalId()?->toString(),
            'total_amount' => $invoice->totalAmount()->toDecimalString(),
            'lines' => array_map(static fn (InvoiceLine $line): array => [
                'description' => $line->description(),
                'quantity' => $line->quantity(),
                'unit_price' => $line->unitPrice()->toDecimalString(),
                'line_amount' => $line->lineAmount()->toDecimalString(),
            ], $invoice->lines()),
        ];
    }
}
