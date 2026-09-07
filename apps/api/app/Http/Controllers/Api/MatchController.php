<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankTransactionId;
use App\Domain\Banking\Exception\BankTransactionAlreadyMatchedException;
use App\Domain\Banking\Exception\NoSuchMatchCandidateException;
use App\Domain\Banking\MatchingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\ConfirmMatchRequest;
use App\Http\Support\CurrentTenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Suggests and confirms Matches (M18, SRS BNK-005) over HTTP.
 */
final class MatchController extends Controller
{
    public function __construct(
        private readonly MatchingService $matchingService,
    ) {}

    public function suggestions(CurrentTenant $currentTenant, string $bankAccountId): JsonResponse
    {
        $candidates = $this->matchingService->suggestFor($currentTenant->id(), BankAccountId::of($bankAccountId));

        return response()->json(['data' => array_map(static fn ($candidate): array => [
            'bank_transaction_id' => $candidate->bankTransactionId()->toString(),
            'journal_id' => $candidate->journalId()->toString(),
            'source_type' => $candidate->sourceType()->name,
            'rationale' => $candidate->rationale(),
        ], $candidates)]);
    }

    public function confirm(ConfirmMatchRequest $request, CurrentTenant $currentTenant, string $bankTransactionId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $match = $this->matchingService->confirm(
                $currentTenant->id(),
                BankTransactionId::of($bankTransactionId),
                JournalId::of($request->string('journal_id')->toString()),
                ActorReference::of($user->id),
            );
        } catch (BankTransactionAlreadyMatchedException|NoSuchMatchCandidateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $match->id()->toString(),
            'bank_transaction_id' => $match->bankTransactionId()->toString(),
            'journal_id' => $match->journalId()->toString(),
            'source_type' => $match->sourceType()->name,
            'rationale' => $match->rationale(),
        ], 201);
    }
}
