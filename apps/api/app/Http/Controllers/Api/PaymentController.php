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
use App\Domain\Payments\Exception\InvalidPaymentDepositAccountTypeException;
use App\Domain\Payments\Exception\InvalidPaymentReceivableAccountTypeException;
use App\Domain\Payments\Payment;
use App\Domain\Payments\PaymentId;
use App\Domain\Payments\PaymentRecordingService;
use App\Domain\Payments\RecordPaymentCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payments\StorePaymentRequest;
use App\Http\Support\CurrentTenant;
use App\Http\Support\DeterministicIdempotentId;
use App\Http\Support\DocumentPartyFormatter;
use App\Http\Support\DocumentPdfBuilder;
use App\Infrastructure\Customers\CustomerRepository;
use App\Infrastructure\Payments\PaymentAllocationRepository;
use App\Infrastructure\Payments\PaymentRepository;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records and lists a Tenant's own Payments (M21, Modul 7 phase 3) —
 * mirrors {@see IncomeController}'s own HTTP
 * contract exactly: an `Idempotency-Key` header is required, since
 * recording a Payment always posts a Journal.
 *
 * {@see pdf()} (AETS-017, added 2026-09-17) reuses the identical
 * shared PDF template/builder Invoice and Quotation already use — a
 * customer-facing receipt for money already received, never a draft
 * or an invoice-like document.
 */
final class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentRecordingService $paymentService,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentAllocationRepository $allocationRepository,
        private readonly CustomerRepository $customerRepository,
    ) {}

    public function index(CurrentTenant $currentTenant): JsonResponse
    {
        $payments = $this->paymentRepository->findAllByTenant($currentTenant->id());

        return response()->json(['data' => array_map(fn (Payment $p): array => $this->toArray($p), $payments)]);
    }

    public function show(CurrentTenant $currentTenant, string $paymentId): JsonResponse
    {
        $payment = $this->paymentRepository->findById($currentTenant->id(), PaymentId::of($paymentId));

        if ($payment === null) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        return response()->json($this->toArray($payment));
    }

    public function pdf(CurrentTenant $currentTenant, string $paymentId): Response|JsonResponse
    {
        $payment = $this->paymentRepository->findById($currentTenant->id(), PaymentId::of($paymentId));

        if ($payment === null) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $customer = $this->customerRepository->findById($currentTenant->id(), $payment->customerId());
        $businessProfile = BusinessProfile::query()->find($currentTenant->id()->toString());
        $reference = $payment->reference();

        return DocumentPdfBuilder::build(
            sprintf('receipt-%s.pdf', substr($payment->id()->toString(), 0, 8)),
            'pdf.document',
            [
                'documentTypeLabel' => 'RECEIPT',
                'documentNumber' => $reference ?? sprintf('#%s', strtoupper(substr($payment->id()->toString(), 0, 8))),
                'statusLabel' => 'PAID',
                'issueDate' => null,
                'secondaryDateLabel' => 'Payment Date',
                'secondaryDate' => $payment->paymentDate()->format('Y-m-d'),
                'seller' => DocumentPartyFormatter::sellerFromBusinessProfile($businessProfile),
                'buyer' => DocumentPartyFormatter::buyerFromCustomer($customer),
                'lines' => [[
                    'description' => $reference === null ? 'Payment received' : sprintf('Payment received (%s)', $reference),
                    'quantity' => 1,
                    'unit_price' => $payment->amount()->toDecimalString(),
                    'line_amount' => $payment->amount()->toDecimalString(),
                ]],
                'totalAmount' => $payment->amount()->toDecimalString(),
                'currency' => $payment->amount()->currency()->identifier(),
            ],
        );
    }

    public function store(StorePaymentRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $idempotencyKeyHeader = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKeyHeader) || $idempotencyKeyHeader === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $idempotencyKey = IdempotencyKey::of($idempotencyKeyHeader);
        $reference = $request->string('reference')->toString();

        $command = new RecordPaymentCommand(
            PaymentId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'payment')),
            JournalId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'journal')),
            $idempotencyKey,
            $currentTenant->id(),
            ActorReference::of($user->id),
            CustomerId::of($request->string('customer_id')->toString()),
            Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
            new \DateTimeImmutable($request->string('payment_date')->toString()),
            AccountId::of($request->string('deposit_account_id')->toString()),
            AccountId::of($request->string('receivable_account_id')->toString()),
            $reference === '' ? null : $reference,
        );

        try {
            $result = $this->paymentService->record($command);
        } catch (
            RejectedAccountReferenceException|
            RejectedClosedPeriodPostingException|
            RejectedConflictingIdempotencyReuseException|
            InvalidPaymentDepositAccountTypeException|
            InvalidPaymentReceivableAccountTypeException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $payment = $result->payment();

        return response()->json($this->toArray($payment), $result->isNewlyRecorded() ? 201 : 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Payment $payment): array
    {
        $allocated = $this->allocationRepository->sumForPayment($payment->tenantId(), $payment->id(), $payment->amount()->currency());
        $unallocated = $payment->amount()->subtract($allocated);

        return [
            'id' => $payment->id()->toString(),
            'customer_id' => $payment->customerId()->toString(),
            'amount' => $payment->amount()->toDecimalString(),
            'payment_date' => $payment->paymentDate()->format('Y-m-d'),
            'deposit_account_id' => $payment->depositAccountId()->toString(),
            'receivable_account_id' => $payment->receivableAccountId()->toString(),
            'journal_id' => $payment->journalId()->toString(),
            'reference' => $payment->reference(),
            'allocated_amount' => $allocated->toDecimalString(),
            'unallocated_amount' => $unallocated->toDecimalString(),
        ];
    }
}
