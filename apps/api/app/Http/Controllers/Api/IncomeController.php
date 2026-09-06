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
use App\Domain\Transactions\Income\Exception\InvalidDepositAccountTypeException;
use App\Domain\Transactions\Income\Exception\InvalidIncomeAccountTypeException;
use App\Domain\Transactions\Income\IncomeId;
use App\Domain\Transactions\Income\IncomeRecordingService;
use App\Domain\Transactions\Income\RecordIncomeCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreIncomeRequest;
use App\Http\Support\CurrentTenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Wraps {@see IncomeRecordingService} (M9) over HTTP — mirrors
 * {@see ExpenseController} exactly, for the identical reason.
 */
final class IncomeController extends Controller
{
    public function __construct(
        private readonly IncomeRecordingService $incomeService,
    ) {}

    public function store(StoreIncomeRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $evidenceReference = $request->string('evidence_reference')->toString();

        $command = new RecordIncomeCommand(
            IncomeId::of((string) Str::uuid()),
            JournalId::of((string) Str::uuid()),
            IdempotencyKey::of($idempotencyKey),
            $currentTenant->id(),
            ActorReference::of($user->id),
            Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
            new \DateTimeImmutable($request->string('transaction_date')->toString()),
            AccountId::of($request->string('income_account_id')->toString()),
            AccountId::of($request->string('deposit_account_id')->toString()),
            $request->string('description')->toString(),
            $evidenceReference === '' ? null : EvidenceReference::of($evidenceReference),
        );

        try {
            $result = $this->incomeService->record($command);
        } catch (
            RejectedAccountReferenceException|
            RejectedConflictingIdempotencyReuseException|
            InvalidIncomeAccountTypeException|
            InvalidDepositAccountTypeException|
            InvalidMoneyAmountException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $income = $result->income();

        return response()->json([
            'id' => $income->id()->toString(),
            'journal_id' => $income->journalId()->toString(),
            'amount' => $income->amount()->toDecimalString(),
            'transaction_date' => $income->transactionDate()->format('Y-m-d'),
            'description' => $income->description(),
            'is_newly_recorded' => $result->isNewlyRecorded(),
        ], $result->isNewlyRecorded() ? 201 : 200);
    }
}
