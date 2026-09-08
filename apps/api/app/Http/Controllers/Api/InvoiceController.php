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
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoicing\IssueInvoiceRequest;
use App\Http\Requests\Invoicing\StoreInvoiceRequest;
use App\Http\Requests\Invoicing\UpdateInvoiceRequest;
use App\Http\Support\CurrentTenant;
use App\Http\Support\DeterministicIdempotentId;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Invoicing\InvoiceRepository;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Creates, edits, deletes, lists, and issues a Tenant's own Invoices
 * (M20, Modul 7 phase 2). A Draft Invoice never touches the ledger —
 * only {@see issue()} does, via {@see InvoiceIssuingService}, mirroring
 * every other Transactions module's own HTTP contract (an
 * `Idempotency-Key` header required on the one endpoint that posts).
 */
final class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly InvoiceAccountTypeValidator $accountTypeValidator,
        private readonly InvoiceIssuingService $issuingService,
    ) {}

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $invoices = $this->invoiceRepository->findAllByTenant($currentTenant->id());

        return response()->json(['data' => array_map(fn (Invoice $i): array => $this->toArray($i), $invoices)]);
    }

    public function show(CurrentTenant $currentTenant, string $invoiceId): JsonResponse
    {
        $invoice = $this->invoiceRepository->findById($currentTenant->id(), InvoiceId::of($invoiceId));

        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        return response()->json($this->toArray($invoice));
    }

    public function store(StoreInvoiceRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $customerId = CustomerId::of($request->string('customer_id')->toString());

        if ($this->customerRepository->findById($currentTenant->id(), $customerId) === null) {
            return response()->json(['message' => 'Customer not found.'], 422);
        }

        $receivableAccountId = AccountId::of($request->string('receivable_account_id')->toString());
        $revenueAccountId = AccountId::of($request->string('revenue_account_id')->toString());

        try {
            $this->accountTypeValidator->validate($currentTenant->id(), $receivableAccountId, $revenueAccountId);

            $invoice = Invoice::draft(
                InvoiceId::of((string) Str::uuid()),
                $currentTenant->id(),
                $customerId,
                new \DateTimeImmutable($request->string('due_date')->toString()),
                $receivableAccountId,
                $revenueAccountId,
                $this->linesFromRequest($request->array('lines')),
                Currency::of('MYR'),
            );
        } catch (
            RejectedAccountReferenceException|
            InvalidReceivableAccountTypeException|
            InvalidRevenueAccountTypeException|
            InvalidInvoiceLineException|
            TooManyInvoiceLinesException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->invoiceRepository->save($invoice);

        return response()->json($this->toArray($invoice), 201);
    }

    public function update(UpdateInvoiceRequest $request, CurrentTenant $currentTenant, string $invoiceId): JsonResponse
    {
        $existing = $this->invoiceRepository->findById($currentTenant->id(), InvoiceId::of($invoiceId));

        if ($existing === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $receivableAccountId = AccountId::of($request->string('receivable_account_id')->toString());
        $revenueAccountId = AccountId::of($request->string('revenue_account_id')->toString());

        try {
            $this->accountTypeValidator->validate($currentTenant->id(), $receivableAccountId, $revenueAccountId);

            $invoice = $existing->update(
                new \DateTimeImmutable($request->string('due_date')->toString()),
                $receivableAccountId,
                $revenueAccountId,
                $this->linesFromRequest($request->array('lines')),
            );
        } catch (
            RejectedAccountReferenceException|
            InvalidReceivableAccountTypeException|
            InvalidRevenueAccountTypeException|
            InvalidInvoiceLineException|
            TooManyInvoiceLinesException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InvoiceNotEditableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $this->invoiceRepository->update($invoice);

        return response()->json($this->toArray($invoice));
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
