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
use App\Domain\Transactions\Transfer\Exception\InvalidTransferAccountTypeException;
use App\Domain\Transactions\Transfer\Exception\SameAccountTransferException;
use App\Domain\Transactions\Transfer\RecordTransferCommand;
use App\Domain\Transactions\Transfer\TransferId;
use App\Domain\Transactions\Transfer\TransferRecordingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\StoreTransferRequest;
use App\Http\Support\CurrentTenant;
use App\Http\Support\DeterministicIdempotentId;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Wraps {@see TransferRecordingService} (M14) over HTTP — mirrors
 * {@see IncomeController} exactly, for the identical reason, including
 * deriving `TransferId`/`JournalId` deterministically via
 * {@see DeterministicIdempotentId} rather than freshly random.
 */
final class TransferController extends Controller
{
    public function __construct(
        private readonly TransferRecordingService $transferService,
    ) {}

    public function store(StoreTransferRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $idempotencyKeyHeader = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKeyHeader) || $idempotencyKeyHeader === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $evidenceReference = $request->string('evidence_reference')->toString();
        $idempotencyKey = IdempotencyKey::of($idempotencyKeyHeader);

        $command = new RecordTransferCommand(
            TransferId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'transfer')),
            JournalId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'journal')),
            $idempotencyKey,
            $currentTenant->id(),
            ActorReference::of($user->id),
            Money::fromDecimalString($request->string('amount')->toString(), Currency::of('MYR')),
            new \DateTimeImmutable($request->string('transaction_date')->toString()),
            AccountId::of($request->string('source_account_id')->toString()),
            AccountId::of($request->string('destination_account_id')->toString()),
            $request->string('description')->toString(),
            $evidenceReference === '' ? null : EvidenceReference::of($evidenceReference),
        );

        try {
            $result = $this->transferService->record($command);
        } catch (
            RejectedAccountReferenceException|
            RejectedClosedPeriodPostingException|
            RejectedConflictingIdempotencyReuseException|
            InvalidTransferAccountTypeException|
            SameAccountTransferException|
            InvalidMoneyAmountException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $transfer = $result->transfer();

        return response()->json([
            'id' => $transfer->id()->toString(),
            'journal_id' => $transfer->journalId()->toString(),
            'amount' => $transfer->amount()->toDecimalString(),
            'transaction_date' => $transfer->transactionDate()->format('Y-m-d'),
            'description' => $transfer->description(),
            'is_newly_recorded' => $result->isNewlyRecorded(),
        ], $result->isNewlyRecorded() ? 201 : 200);
    }
}
