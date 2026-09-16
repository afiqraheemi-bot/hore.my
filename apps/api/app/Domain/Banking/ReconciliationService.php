<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Exception\UnresolvedMoneySignPolicyException;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\Exception\ReconciliationComputationExceedsSupportedRangeException;
use App\Domain\Banking\Exception\ReconciliationHasUnmatchedTransactionsException;
use App\Domain\Banking\Exception\ReconciliationNotBalancedException;
use App\Domain\Banking\Exception\ReconciliationPeriodOverlapException;
use App\Domain\Banking\Exception\ReconciliationReopenRequiresReasonException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Banking\BankTransactionRepository;
use App\Infrastructure\Banking\MatchRepository;
use App\Infrastructure\Banking\ReconciliationRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * The application service orchestrating a Reconciliation's lifecycle
 * (M18, SRS BNK-006/BNK-007) — the only class that persists
 * {@see Reconciliation} state changes or computes a
 * {@see ReconciliationDifference} against real BankTransaction data;
 * {@see Reconciliation} itself only enforces state-machine validity.
 */
final class ReconciliationService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly ReconciliationRepository $reconciliationRepository,
        private readonly MatchRepository $matchRepository,
    ) {}

    /**
     * @throws ReconciliationPeriodOverlapException if `$periodStart`..`$periodEnd`
     *                                              overlaps any existing Reconciliation's period for this Bank
     *                                              Account, in any lifecycle state (`BNK-015`).
     */
    public function open(
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        Money $openingBalance,
        Money $closingBalance,
    ): Reconciliation {
        return $this->connection->transaction(function () use ($tenantId, $bankAccountId, $periodStart, $periodEnd, $openingBalance, $closingBalance): Reconciliation {
            // Locks the BankAccount row so two concurrent `open()` calls
            // for the same Bank Account serialize instead of both
            // reading a stale existing-period list below (BNK-015).
            $this->connection->table('bank_accounts')
                ->where('tenant_id', $tenantId->toString())
                ->where('id', $bankAccountId->toString())
                ->lockForUpdate()
                ->first();

            foreach ($this->reconciliationRepository->findByBankAccount($tenantId, $bankAccountId) as $existing) {
                if (self::periodsOverlap($periodStart, $periodEnd, $existing->periodStart(), $existing->periodEnd())) {
                    throw ReconciliationPeriodOverlapException::forOverlap($bankAccountId, $existing->id());
                }
            }

            $reconciliation = Reconciliation::open(
                ReconciliationId::of((string) Str::uuid()),
                $tenantId,
                $bankAccountId,
                $periodStart,
                $periodEnd,
                $openingBalance,
                $closingBalance,
                new \DateTimeImmutable,
            );

            $this->reconciliationRepository->record($reconciliation);

            return $reconciliation;
        });
    }

    private static function periodsOverlap(
        \DateTimeImmutable $aStart,
        \DateTimeImmutable $aEnd,
        \DateTimeImmutable $bStart,
        \DateTimeImmutable $bEnd,
    ): bool {
        return $aStart <= $bEnd && $aEnd >= $bStart;
    }

    /**
     * The implied closing balance is `openingBalance + total MoneyIn -
     * total MoneyOut` for every BankTransaction on this Reconciliation's
     * BankAccount within `[periodStart, periodEnd]`, regardless of
     * Match status — this is a data-integrity check on the *import*
     * (are the statement rows themselves internally consistent with the
     * stated balances), independent of how many rows are Matched yet.
     *
     * @throws ReconciliationComputationExceedsSupportedRangeException
     *                                                                 if the computation would require an intermediate negative Money
     *                                                                 value (see that exception's own docblock).
     */
    public function computeDifference(TenantId $tenantId, Reconciliation $reconciliation): ReconciliationDifference
    {
        $currency = $reconciliation->openingBalance()->currency();
        $zero = Money::fromMinorUnits(MinorUnits::of('0'), $currency);

        $totalIn = $zero;
        $totalOut = $zero;

        foreach ($this->bankTransactionRepository->findByBankAccount($tenantId, $reconciliation->bankAccountId()) as $bankTransaction) {
            if ($bankTransaction->transactionDate() < $reconciliation->periodStart() || $bankTransaction->transactionDate() > $reconciliation->periodEnd()) {
                continue;
            }

            if ($bankTransaction->direction() === BankTransactionDirection::MoneyIn) {
                $totalIn = $totalIn->add($bankTransaction->amount());
            } else {
                $totalOut = $totalOut->add($bankTransaction->amount());
            }
        }

        try {
            $impliedClosingBalance = $reconciliation->openingBalance()->add($totalIn)->subtract($totalOut);
        } catch (UnresolvedMoneySignPolicyException) {
            throw ReconciliationComputationExceedsSupportedRangeException::forNegativeIntermediateBalance();
        }

        return ReconciliationDifference::compute($reconciliation->closingBalance(), $impliedClosingBalance);
    }

    public function startReview(TenantId $tenantId, ReconciliationId $id): Reconciliation
    {
        return $this->connection->transaction(function () use ($tenantId, $id): Reconciliation {
            $reconciliation = $this->reconciliationRepository->getByIdForUpdate($tenantId, $id)->startReview();
            $this->reconciliationRepository->updateState($reconciliation);

            return $reconciliation;
        });
    }

    /**
     * @throws ReconciliationNotBalancedException if
     *                                            {@see computeDifference()} is not zero.
     */
    public function markBalanced(TenantId $tenantId, ReconciliationId $id): Reconciliation
    {
        return $this->connection->transaction(function () use ($tenantId, $id): Reconciliation {
            $current = $this->reconciliationRepository->getByIdForUpdate($tenantId, $id);
            $difference = $this->computeDifference($tenantId, $current);

            if (! $difference->isZero()) {
                throw ReconciliationNotBalancedException::forDifference($id, $difference);
            }

            $reconciliation = $current->markBalanced();
            $this->reconciliationRepository->updateState($reconciliation);

            return $reconciliation;
        });
    }

    /**
     * Recomputes the live difference at the final transition boundary.
     * A Reconciliation that was Balanced earlier may no longer be
     * balanced if additional statement rows arrived before completion;
     * the authoritative Completed invariant is therefore checked here
     * again instead of trusting stale state.
     *
     * @throws ReconciliationNotBalancedException if the current live
     *                                            difference is not zero.
     */
    /**
     * @throws ReconciliationNotBalancedException if the live difference
     *                                            is not zero.
     * @throws ReconciliationHasUnmatchedTransactionsException if any
     *                                                         in-period BankTransaction has no confirmed Match
     *                                                         (`BNK-016`) — checked even when the difference is
     *                                                         already exact zero, since zero arithmetic difference
     *                                                         alone does not prove every line is explained.
     */
    public function complete(TenantId $tenantId, ReconciliationId $id): Reconciliation
    {
        return $this->connection->transaction(function () use ($tenantId, $id): Reconciliation {
            $current = $this->reconciliationRepository->getByIdForUpdate($tenantId, $id);
            $difference = $this->computeDifference($tenantId, $current);

            if (! $difference->isZero()) {
                throw ReconciliationNotBalancedException::forDifference($id, $difference);
            }

            $inPeriodIds = $this->inPeriodBankTransactionIds($tenantId, $current);
            $matchedIds = $inPeriodIds === [] ? [] : $this->matchRepository->matchedBankTransactionIds($tenantId, $inPeriodIds);
            $unmatchedCount = count($inPeriodIds) - count($matchedIds);

            if ($unmatchedCount > 0) {
                throw ReconciliationHasUnmatchedTransactionsException::forReconciliation($id, $unmatchedCount);
            }

            $completedAt = new \DateTimeImmutable;
            $reconciliation = $current->complete($completedAt);
            $this->reconciliationRepository->updateState($reconciliation);

            // Durably records exactly what was verified (BNK-017): a
            // BankTransaction imported afterward — even dated inside
            // this period — is never silently absorbed into this
            // result. See findLateUnreconciledTransactionIds().
            $this->reconciliationRepository->recordCompletionSnapshot($tenantId, $id, $inPeriodIds, $completedAt);

            return $reconciliation;
        });
    }

    /**
     * The BankTransactions imported after this `Completed` Reconciliation's
     * last completion snapshot, but whose Transaction Date still falls
     * inside its period (AETS-008 §12.3, `BNK-017`) — never silently
     * folded into the already-recorded result. Reusing `reopen()`
     * (§7) followed by a new `complete()` is the only way to
     * incorporate them. Returns an empty list for a Reconciliation that
     * is not (or no longer) `Completed`.
     *
     * @return list<BankTransactionId>
     */
    public function findLateUnreconciledTransactionIds(TenantId $tenantId, Reconciliation $reconciliation): array
    {
        if ($reconciliation->state() !== ReconciliationState::Completed) {
            return [];
        }

        $inPeriodIds = $this->inPeriodBankTransactionIds($tenantId, $reconciliation);

        if ($inPeriodIds === []) {
            return [];
        }

        $snapshotIds = $this->reconciliationRepository->completionSnapshotBankTransactionIds($tenantId, $reconciliation->id());
        $snapshotIdStrings = array_map(static fn (BankTransactionId $id): string => $id->toString(), $snapshotIds);

        return array_values(array_filter(
            $inPeriodIds,
            static fn (BankTransactionId $id): bool => ! in_array($id->toString(), $snapshotIdStrings, true),
        ));
    }

    /**
     * @return list<BankTransactionId>
     */
    private function inPeriodBankTransactionIds(TenantId $tenantId, Reconciliation $reconciliation): array
    {
        $ids = [];

        foreach ($this->bankTransactionRepository->findByBankAccount($tenantId, $reconciliation->bankAccountId()) as $bankTransaction) {
            if ($bankTransaction->transactionDate() < $reconciliation->periodStart() || $bankTransaction->transactionDate() > $reconciliation->periodEnd()) {
                continue;
            }

            $ids[] = $bankTransaction->id();
        }

        return $ids;
    }

    /**
     * @throws ReconciliationReopenRequiresReasonException if `$reason`
     *                                                     is empty.
     */
    public function reopen(TenantId $tenantId, ReconciliationId $id, string $reason, ActorReference $actor): Reconciliation
    {
        if (trim($reason) === '') {
            throw ReconciliationReopenRequiresReasonException::forEmptyReason();
        }

        return $this->connection->transaction(function () use ($tenantId, $id, $reason, $actor): Reconciliation {
            $reconciliation = $this->reconciliationRepository->getByIdForUpdate($tenantId, $id)->reopen();

            $this->reconciliationRepository->updateState($reconciliation);
            $this->reconciliationRepository->recordReopening(new ReconciliationReopening(
                (string) Str::uuid(),
                $tenantId,
                $id,
                $reason,
                $actor,
                new \DateTimeImmutable,
            ));

            // Clears the invalidated completion snapshot (BNK-017) —
            // mirrors reopen() already clearing completed_at itself.
            // The permanent audit trail of why/when lives in the
            // reopening row just recorded above; a later complete()
            // gets a fresh snapshot rather than an accumulated one.
            $this->reconciliationRepository->clearCompletionSnapshot($tenantId, $id);

            return $reconciliation;
        });
    }
}
