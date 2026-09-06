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
use App\Domain\Accounting\Posting\Exception\RejectedClosedPeriodPostingException;
use App\Domain\Accounting\Posting\Exception\RejectedConflictingIdempotencyReuseException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidCashAccountTypeException;
use App\Domain\Transactions\OwnerEquity\Exception\InvalidEquityAccountTypeException;
use App\Domain\Transactions\OwnerEquity\OwnerEquityMovementType;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionId;
use App\Domain\Transactions\OwnerEquity\OwnerEquityTransactionRecordingService;
use App\Domain\Transactions\OwnerEquity\RecordOwnerEquityTransactionCommand;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreOwnerEquityTransactionRequest;
use App\Http\Support\CurrentTenant;
use App\Http\Support\DeterministicIdempotentId;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Wraps {@see OwnerEquityTransactionRecordingService} (M15), fixed to
 * {@see OwnerEquityMovementType::Drawing} — see
 * {@see CapitalContributionController}'s own
 * docblock; the only difference between the two controllers is this
 * fixed movement type.
 */
final class OwnerDrawingController extends Controller
{
    public function __construct(
        private readonly OwnerEquityTransactionRecordingService $service,
    ) {}

    public function store(StoreOwnerEquityTransactionRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $idempotencyKeyHeader = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKeyHeader) || $idempotencyKeyHeader === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $evidenceReference = $request->string('evidence_reference')->toString();
        $idempotencyKey = IdempotencyKey::of($idempotencyKeyHeader);

        $command = new RecordOwnerEquityTransactionCommand(
            OwnerEquityTransactionId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'owner-equity')),
            JournalId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'journal')),
            $idempotencyKey,
            $currentTenant->id(),
            ActorReference::of($user->id),
            OwnerEquityMovementType::Drawing,
            Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
            new \DateTimeImmutable($request->string('transaction_date')->toString()),
            AccountId::of($request->string('equity_account_id')->toString()),
            AccountId::of($request->string('cash_account_id')->toString()),
            $request->string('description')->toString(),
            $evidenceReference === '' ? null : EvidenceReference::of($evidenceReference),
        );

        try {
            $result = $this->service->record($command);
        } catch (
            RejectedAccountReferenceException|
            RejectedClosedPeriodPostingException|
            RejectedConflictingIdempotencyReuseException|
            InvalidEquityAccountTypeException|
            InvalidCashAccountTypeException|
            InvalidMoneyAmountException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $transaction = $result->transaction();

        return response()->json([
            'id' => $transaction->id()->toString(),
            'journal_id' => $transaction->journalId()->toString(),
            'amount' => $transaction->amount()->toDecimalString(),
            'transaction_date' => $transaction->transactionDate()->format('Y-m-d'),
            'description' => $transaction->description(),
            'is_newly_recorded' => $result->isNewlyRecorded(),
        ], $result->isNewlyRecorded() ? 201 : 200);
    }
}
