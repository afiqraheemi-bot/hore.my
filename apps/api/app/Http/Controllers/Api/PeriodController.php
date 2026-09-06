<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Period\Exception\InvalidRetainedEarningsAccountTypeException;
use App\Domain\Accounting\Period\Exception\NothingToCloseException;
use App\Domain\Accounting\Period\Exception\PeriodAlreadyClosedException;
use App\Domain\Accounting\Period\PeriodClosingCommand;
use App\Domain\Accounting\Period\PeriodClosingService;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Accounting\Posting\IdempotencyKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ClosePeriodRequest;
use App\Http\Support\CurrentTenant;
use App\Http\Support\DeterministicIdempotentId;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Wraps {@see PeriodClosingService} (AETS-014) over HTTP — no new
 * business logic. Mirrors {@see ExpenseController}'s idempotency
 * design exactly: the closing Journal's identity is derived
 * deterministically from `(Tenant, Idempotency-Key)` via
 * {@see DeterministicIdempotentId}, never freshly random, so a genuine
 * HTTP retry replays instead of being rejected as a conflicting reuse.
 */
final class PeriodController extends Controller
{
    public function __construct(
        private readonly PeriodClosingService $periodClosingService,
    ) {}

    public function close(ClosePeriodRequest $request, CurrentTenant $currentTenant): JsonResponse
    {
        $idempotencyKeyHeader = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKeyHeader) || $idempotencyKeyHeader === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $idempotencyKey = IdempotencyKey::of($idempotencyKeyHeader);

        $command = new PeriodClosingCommand(
            $currentTenant->id(),
            $idempotencyKey,
            ActorReference::of($user->id),
            JournalId::of(DeterministicIdempotentId::derive($currentTenant->id(), $idempotencyKey, 'period-closing')),
            new \DateTimeImmutable($request->string('closed_through_date')->toString()),
            AccountId::of($request->string('retained_earnings_account_id')->toString()),
        );

        try {
            $result = $this->periodClosingService->close($command);
        } catch (
            RejectedAccountReferenceException|
            InvalidRetainedEarningsAccountTypeException|
            PeriodAlreadyClosedException|
            NothingToCloseException $e
        ) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $closure = $result->closure();

        return response()->json([
            'closed_through_date' => $closure->closedThroughDate()->format('Y-m-d'),
            'closing_journal_id' => $closure->closingJournalId()->toString(),
            'closed_at' => $closure->closedAt()->format(DATE_ATOM),
            'is_newly_closed' => $result->isNewlyClosed(),
        ], $result->isNewlyClosed() ? 201 : 200);
    }
}
