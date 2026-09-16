<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Exception\InvalidInvoiceLineException;
use App\Domain\Invoicing\Exception\InvalidReceivableAccountTypeException;
use App\Domain\Invoicing\Exception\InvalidRevenueAccountTypeException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Quotations\Exception\EmptyQuotationCannotBeSentException;
use App\Domain\Quotations\Exception\InvalidQuotationLineException;
use App\Domain\Quotations\Exception\InvalidQuotationStatusTransitionException;
use App\Domain\Quotations\Exception\InvalidQuotationValidUntilException;
use App\Domain\Quotations\Exception\QuotationNotEditableException;
use App\Domain\Quotations\Exception\QuotationNotFoundException;
use App\Domain\Quotations\Exception\TooManyQuotationLinesException;
use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationConversionService;
use App\Domain\Quotations\QuotationId;
use App\Domain\Quotations\QuotationLine;
use App\Domain\Quotations\QuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\ConvertQuotationRequest;
use App\Http\Requests\Quotations\SendQuotationRequest;
use App\Http\Requests\Quotations\StoreQuotationRequest;
use App\Http\Requests\Quotations\UpdateQuotationRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Quotations\QuotationNumberGenerator;
use App\Infrastructure\Quotations\QuotationRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Creates, edits, deletes, lists, sends, accepts, rejects, and
 * converts a Tenant's own Quotations (AETS-016). A Quotation never
 * touches the ledger — only {@see convert()} does, and even then only
 * by creating a new Draft Invoice; nothing here ever posts a Journal.
 * Mirrors {@see InvoiceController}'s own HTTP shape wherever the two
 * concepts are structurally parallel.
 */
final class QuotationController extends Controller
{
    public function __construct(
        private readonly QuotationRepository $quotationRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly QuotationNumberGenerator $numberGenerator,
        private readonly QuotationConversionService $conversionService,
    ) {}

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $quotations = $this->quotationRepository->findAllByTenant($currentTenant->id());

        return response()->json(['data' => array_map(fn (Quotation $q): array => $this->toArray($q), $quotations)]);
    }

    public function show(CurrentTenant $currentTenant, string $quotationId): JsonResponse
    {
        $quotation = $this->quotationRepository->findById($currentTenant->id(), QuotationId::of($quotationId));

        if ($quotation === null) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }

        return response()->json($this->toArray($quotation));
    }

    public function store(StoreQuotationRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $customerId = CustomerId::of($request->string('customer_id')->toString());

        if ($this->customerRepository->findById($currentTenant->id(), $customerId) === null) {
            return response()->json(['message' => 'Customer not found.'], 422);
        }

        try {
            $quotation = Quotation::draft(
                QuotationId::of((string) Str::uuid()),
                $currentTenant->id(),
                $customerId,
                new \DateTimeImmutable($request->string('valid_until')->toString()),
                $this->linesFromRequest($request->array('lines')),
                Currency::of('MYR'),
            );
        } catch (InvalidQuotationLineException|TooManyQuotationLinesException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->quotationRepository->save($quotation);

        return response()->json($this->toArray($quotation), 201);
    }

    public function update(UpdateQuotationRequest $request, CurrentTenant $currentTenant, string $quotationId): JsonResponse
    {
        $existing = $this->quotationRepository->findById($currentTenant->id(), QuotationId::of($quotationId));

        if ($existing === null) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }

        $customerId = CustomerId::of($request->string('customer_id')->toString());

        if ($this->customerRepository->findById($currentTenant->id(), $customerId) === null) {
            return response()->json(['message' => 'Customer not found.'], 422);
        }

        try {
            $quotation = $existing->update(
                $customerId,
                new \DateTimeImmutable($request->string('valid_until')->toString()),
                $this->linesFromRequest($request->array('lines')),
            );
        } catch (InvalidQuotationLineException|TooManyQuotationLinesException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (QuotationNotEditableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $this->quotationRepository->update($quotation);

        return response()->json($this->toArray($quotation));
    }

    public function destroy(CurrentTenant $currentTenant, string $quotationId): JsonResponse
    {
        $id = QuotationId::of($quotationId);
        $quotation = $this->quotationRepository->findById($currentTenant->id(), $id);

        if ($quotation === null) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }

        if ($quotation->status() !== QuotationStatus::Draft) {
            return response()->json(['message' => 'Only a Draft Quotation can be deleted.'], 409);
        }

        $this->quotationRepository->delete($currentTenant->id(), $id);

        return response()->json(null, 204);
    }

    public function send(SendQuotationRequest $request, CurrentTenant $currentTenant, string $quotationId): JsonResponse
    {
        $existing = $this->quotationRepository->findById($currentTenant->id(), QuotationId::of($quotationId));

        if ($existing === null) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }

        try {
            $issueDate = new \DateTimeImmutable($request->string('issue_date')->toString());
            $number = $this->numberGenerator->next($currentTenant->id());
            $quotation = $existing->send($number, $issueDate);
        } catch (
            InvalidQuotationStatusTransitionException|
            EmptyQuotationCannotBeSentException|
            InvalidQuotationValidUntilException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->quotationRepository->markSent($quotation);

        return response()->json($this->toArray($quotation));
    }

    public function accept(Request $request, CurrentTenant $currentTenant, string $quotationId): JsonResponse
    {
        return $this->transition($currentTenant, $quotationId, static fn (Quotation $q): Quotation => $q->accept());
    }

    public function reject(Request $request, CurrentTenant $currentTenant, string $quotationId): JsonResponse
    {
        return $this->transition($currentTenant, $quotationId, static fn (Quotation $q): Quotation => $q->reject());
    }

    public function convert(ConvertQuotationRequest $request, CurrentTenant $currentTenant, string $quotationId): JsonResponse
    {
        $invoiceId = InvoiceId::of((string) Str::uuid());

        try {
            $invoice = $this->conversionService->convert(
                $currentTenant->id(),
                QuotationId::of($quotationId),
                $invoiceId,
                AccountId::of($request->string('receivable_account_id')->toString()),
                AccountId::of($request->string('revenue_account_id')->toString()),
                new \DateTimeImmutable($request->string('due_date')->toString()),
            );
        } catch (QuotationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (
            InvalidQuotationStatusTransitionException|
            RejectedAccountReferenceException|
            InvalidReceivableAccountTypeException|
            InvalidRevenueAccountTypeException|
            InvalidInvoiceLineException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->invoiceToArray($invoice), 201);
    }

    /**
     * @param  \Closure(Quotation): Quotation  $transition
     */
    private function transition(CurrentTenant $currentTenant, string $quotationId, \Closure $transition): JsonResponse
    {
        $existing = $this->quotationRepository->findById($currentTenant->id(), QuotationId::of($quotationId));

        if ($existing === null) {
            return response()->json(['message' => 'Quotation not found.'], 404);
        }

        try {
            $quotation = $transition($existing);
        } catch (InvalidQuotationStatusTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->quotationRepository->updateStatus($quotation);

        return response()->json($this->toArray($quotation));
    }

    /**
     * @param  array<int, array{description: string, quantity: int, unit_price: string}>  $lines
     * @return list<QuotationLine>
     */
    private function linesFromRequest(array $lines): array
    {
        return array_values(array_map(fn (array $line): QuotationLine => QuotationLine::of(
            $line['description'],
            $line['quantity'],
            Money::fromDecimalString($line['unit_price'], Currency::of('MYR')),
        ), $lines));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id()->toString(),
            'customer_id' => $quotation->customerId()->toString(),
            'quotation_number' => $quotation->quotationNumber(),
            'status' => $quotation->status()->name,
            'issue_date' => $quotation->issueDate()?->format('Y-m-d'),
            'valid_until' => $quotation->validUntil()->format('Y-m-d'),
            'converted_invoice_id' => $quotation->convertedInvoiceId()?->toString(),
            'total_amount' => $quotation->totalAmount()->toDecimalString(),
            'lines' => array_map(static fn (QuotationLine $line): array => [
                'description' => $line->description(),
                'quantity' => $line->quantity(),
                'unit_price' => $line->unitPrice()->toDecimalString(),
                'line_amount' => $line->lineAmount()->toDecimalString(),
            ], $quotation->lines()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceToArray(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id()->toString(),
            'customer_id' => $invoice->customerId()->toString(),
            'status' => $invoice->status()->name,
            'due_date' => $invoice->dueDate()->format('Y-m-d'),
            'receivable_account_id' => $invoice->receivableAccountId()->toString(),
            'revenue_account_id' => $invoice->revenueAccountId()->toString(),
            'total_amount' => $invoice->totalAmount()->toDecimalString(),
        ];
    }
}
