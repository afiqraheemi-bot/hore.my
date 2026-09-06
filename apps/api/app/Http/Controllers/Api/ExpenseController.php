<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidMoneyAmountException;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\EvidenceReference;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Transactions\Expense\Exception\InvalidExpenseAccountTypeException;
use App\Domain\Transactions\Expense\Exception\InvalidPaymentAccountTypeException;
use App\Domain\Transactions\Expense\ExpenseId;
use App\Domain\Transactions\Expense\ExpenseRecordingService;
use App\Domain\Transactions\Expense\RecordExpenseCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreExpenseRequest;
use App\Http\Support\CurrentTenant;
use App\Http\Support\DeterministicIdempotentId;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Wraps {@see ExpenseRecordingService} (M7) over HTTP — no new business
 * logic. Requires an `Idempotency-Key` request header so a client can
 * safely retry a request that timed out without risking a duplicate
 * posting (AETS-007's own idempotency contract is what actually
 * enforces this; the header only carries the caller-chosen key into
 * it).
 *
 * `ExpenseId`/`JournalId` are derived deterministically from
 * `(Tenant, Idempotency-Key)` via {@see DeterministicIdempotentId},
 * never freshly random — see that class's own docblock for why a
 * random identifier would silently break retry safety, since
 * `PostingCommandLogicalEquivalence` treats the proposed Journal
 * identity as part of what a genuine retry must agree on.
 */
final class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseRecordingService $expenseService,
    ) {}

    public function store(StoreExpenseRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $idempotencyKeyHeader = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKeyHeader) || $idempotencyKeyHeader === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $evidenceReference = $request->string('evidence_reference')->toString();
        $idempotencyKey = IdempotencyKey::of($idempotencyKeyHeader);

        $command = new RecordExpenseCommand(
            ExpenseId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'expense')),
            JournalId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'journal')),
            $idempotencyKey,
            $currentTenant->id(),
            ActorReference::of($user->id),
            Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
            new \DateTimeImmutable($request->string('transaction_date')->toString()),
            AccountId::of($request->string('expense_account_id')->toString()),
            AccountId::of($request->string('payment_account_id')->toString()),
            $request->string('description')->toString(),
            $evidenceReference === '' ? null : EvidenceReference::of($evidenceReference),
        );

        try {
            $result = $this->expenseService->record($command);
        } catch (
            RejectedAccountReferenceException|
            RejectedConflictingIdempotencyReuseException|
            InvalidExpenseAccountTypeException|
            InvalidPaymentAccountTypeException|
            InvalidMoneyAmountException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $expense = $result->expense();

        return response()->json([
            'id' => $expense->id()->toString(),
            'journal_id' => $expense->journalId()->toString(),
            'amount' => $expense->amount()->toDecimalString(),
            'transaction_date' => $expense->transactionDate()->format('Y-m-d'),
            'description' => $expense->description(),
            'is_newly_recorded' => $result->isNewlyRecorded(),
        ], $result->isNewlyRecorded() ? 201 : 200);
    }
}
