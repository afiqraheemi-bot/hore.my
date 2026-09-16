<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\Exception\InvalidBankAccountIdException;
use App\Domain\Banking\Exception\InvalidReconciliationIdException;
use App\Domain\Banking\Exception\InvalidReconciliationStateTransitionException;
use App\Domain\Banking\Exception\ReconciliationComputationExceedsSupportedRangeException;
use App\Domain\Banking\Exception\ReconciliationNotBalancedException;
use App\Domain\Banking\Exception\ReconciliationNotFoundException;
use App\Domain\Banking\Exception\ReconciliationReopenRequiresReasonException;
use App\Domain\Banking\Reconciliation;
use App\Domain\Banking\ReconciliationDifference;
use App\Domain\Banking\ReconciliationId;
use App\Domain\Banking\ReconciliationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\OpenReconciliationRequest;
use App\Http\Requests\Banking\ReopenReconciliationRequest;
use App\Http\Support\CurrentTenant;
use App\Infrastructure\Banking\BankAccountRepository;
use App\Infrastructure\Banking\ReconciliationRepository;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The Reconciliation lifecycle over HTTP (M18, SRS BNK-006/BNK-007,
 * §10.4): open, list, show (with its live-computed
 * {@see ReconciliationDifference}), and each explicit state transition.
 */
final class ReconciliationController extends Controller
{
    public function __construct(
        private readonly ReconciliationService $reconciliationService,
        private readonly ReconciliationRepository $reconciliationRepository,
        private readonly BankAccountRepository $bankAccountRepository,
    ) {}

    public function index(CurrentTenant $currentTenant, string $bankAccountId): JsonResponse
    {
        try {
            $id = BankAccountId::of($bankAccountId);
        } catch (InvalidBankAccountIdException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($this->bankAccountRepository->findById($currentTenant->id(), $id) === null) {
            return response()->json(['message' => 'BankAccount not found.'], 404);
        }

        $reconciliations = $this->reconciliationRepository->findByBankAccount($currentTenant->id(), $id);

        return response()->json(['data' => array_map(fn (Reconciliation $r): array => $this->toArray($r, $currentTenant), $reconciliations)]);
    }

    public function store(OpenReconciliationRequest $request, CurrentTenant $currentTenant, string $bankAccountId): JsonResponse
    {
        try {
            $id = BankAccountId::of($bankAccountId);
        } catch (InvalidBankAccountIdException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($this->bankAccountRepository->findById($currentTenant->id(), $id) === null) {
            return response()->json(['message' => 'BankAccount not found.'], 404);
        }

        $currency = Currency::of('MYR');

        $reconciliation = $this->reconciliationService->open(
            $currentTenant->id(),
            $id,
            new \DateTimeImmutable($request->string('period_start')->toString()),
            new \DateTimeImmutable($request->string('period_end')->toString()),
            Money::fromDecimalString($request->string('opening_balance')->toString(), $currency),
            Money::fromDecimalString($request->string('closing_balance')->toString(), $currency),
        );

        return response()->json($this->toArray($reconciliation, $currentTenant), 201);
    }

    public function show(CurrentTenant $currentTenant, string $reconciliationId): JsonResponse
    {
        try {
            $reconciliation = $this->reconciliationRepository->getById($currentTenant->id(), ReconciliationId::of($reconciliationId));
        } catch (InvalidReconciliationIdException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ReconciliationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($this->toArray($reconciliation, $currentTenant));
    }

    public function startReview(CurrentTenant $currentTenant, string $reconciliationId): JsonResponse
    {
        return $this->transition(fn () => $this->reconciliationService->startReview($currentTenant->id(), ReconciliationId::of($reconciliationId)), $currentTenant);
    }

    public function markBalanced(CurrentTenant $currentTenant, string $reconciliationId): JsonResponse
    {
        try {
            $reconciliation = $this->reconciliationService->markBalanced($currentTenant->id(), ReconciliationId::of($reconciliationId));
        } catch (InvalidReconciliationIdException|InvalidReconciliationStateTransitionException|ReconciliationNotBalancedException|ReconciliationComputationExceedsSupportedRangeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ReconciliationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($this->toArray($reconciliation, $currentTenant));
    }

    public function complete(CurrentTenant $currentTenant, string $reconciliationId): JsonResponse
    {
        return $this->transition(fn () => $this->reconciliationService->complete($currentTenant->id(), ReconciliationId::of($reconciliationId)), $currentTenant);
    }

    public function reopen(ReopenReconciliationRequest $request, CurrentTenant $currentTenant, string $reconciliationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $reconciliation = $this->reconciliationService->reopen(
                $currentTenant->id(),
                ReconciliationId::of($reconciliationId),
                $request->string('reason')->toString(),
                ActorReference::of($user->id),
            );
        } catch (InvalidReconciliationIdException|InvalidReconciliationStateTransitionException|ReconciliationReopenRequiresReasonException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ReconciliationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($this->toArray($reconciliation, $currentTenant));
    }

    /**
     * @param  \Closure(): Reconciliation  $action
     */
    private function transition(\Closure $action, CurrentTenant $currentTenant): JsonResponse
    {
        try {
            $reconciliation = $action();
        } catch (InvalidReconciliationIdException|InvalidReconciliationStateTransitionException|ReconciliationNotBalancedException|ReconciliationComputationExceedsSupportedRangeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ReconciliationNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($this->toArray($reconciliation, $currentTenant));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Reconciliation $reconciliation, CurrentTenant $currentTenant): array
    {
        $difference = null;

        try {
            $difference = $this->reconciliationService->computeDifference($currentTenant->id(), $reconciliation);
        } catch (ReconciliationComputationExceedsSupportedRangeException) {
            // Left null — the response still reports the Reconciliation
            // itself; the difference is simply not computable yet.
        }

        return [
            'id' => $reconciliation->id()->toString(),
            'bank_account_id' => $reconciliation->bankAccountId()->toString(),
            'period_start' => $reconciliation->periodStart()->format('Y-m-d'),
            'period_end' => $reconciliation->periodEnd()->format('Y-m-d'),
            'opening_balance' => $reconciliation->openingBalance()->toDecimalString(),
            'closing_balance' => $reconciliation->closingBalance()->toDecimalString(),
            'state' => $reconciliation->state()->name,
            'completed_at' => $reconciliation->completedAt()?->format(\DateTimeInterface::ATOM),
            'difference' => $difference === null ? null : [
                'amount' => $difference->amount()->toDecimalString(),
                'sign' => $difference->sign()?->name,
                'is_zero' => $difference->isZero(),
            ],
        ];
    }
}
