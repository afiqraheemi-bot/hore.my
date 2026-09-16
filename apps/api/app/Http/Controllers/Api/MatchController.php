<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Journal\Exception\InvalidJournalIdException;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\BankTransactionId;
use App\Domain\Banking\Exception\InvalidBankAccountIdException;
use App\Domain\Banking\Exception\InvalidBankTransactionIdException;
use App\Domain\Banking\Exception\MatchConfirmationConflictException;
use App\Domain\Banking\Exception\NoSuchMatchCandidateException;
use App\Domain\Banking\MatchingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\ConfirmMatchRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Banking\BankAccountRepository;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Suggests and confirms Matches (M18, SRS BNK-005) over HTTP.
 */
final class MatchController extends Controller
{
    public function __construct(
        private readonly MatchingService $matchingService,
        private readonly BankAccountRepository $bankAccountRepository,
    ) {}

    public function suggestions(CurrentTenant $currentTenant, string $bankAccountId): JsonResponse
    {
        try {
            $id = BankAccountId::of($bankAccountId);
        } catch (InvalidBankAccountIdException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($this->bankAccountRepository->findById($currentTenant->id(), $id) === null) {
            return response()->json(['message' => 'BankAccount not found.'], 404);
        }

        $candidates = $this->matchingService->suggestFor($currentTenant->id(), $id);

        return response()->json(['data' => array_map(static fn ($candidate): array => [
            'bank_transaction_id' => $candidate->bankTransactionId()->toString(),
            'journal_id' => $candidate->journalId()->toString(),
            'source_type' => $candidate->sourceType()->name,
            'rationale' => $candidate->rationale(),
            'confidence' => $candidate->confidence()->name,
        ], $candidates)]);
    }

    public function confirm(ConfirmMatchRequest $request, CurrentTenant $currentTenant, string $bankTransactionId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->matchingService->confirm(
                $currentTenant->id(),
                BankTransactionId::of($bankTransactionId),
                JournalId::of($request->string('journal_id')->toString()),
                ActorReference::of($user->id),
            );
        } catch (InvalidBankTransactionIdException|InvalidJournalIdException|NoSuchMatchCandidateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (MatchConfirmationConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $match = $result->match();

        return response()->json([
            'id' => $match->id()->toString(),
            'bank_transaction_id' => $match->bankTransactionId()->toString(),
            'journal_id' => $match->journalId()->toString(),
            'source_type' => $match->sourceType()->name,
            'rationale' => $match->rationale(),
            'confidence' => $match->confidence()->name,
            'is_new_match' => $result->isNewMatch(),
        ], $result->isNewMatch() ? 201 : 200);
    }
}
