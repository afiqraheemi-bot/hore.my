<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Exception\UnresolvedMoneySignPolicyException;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\ActorReference;
use App\Domain\Banking\Exception\ReconciliationComputationExceedsSupportedRangeException;
use App\Domain\Banking\Exception\ReconciliationNotBalancedException;
use App\Domain\Banking\Exception\ReconciliationReopenRequiresReasonException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Banking\BankTransactionRepository;
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
    ) {}

    public function open(
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        Money $openingBalance,
        Money $closingBalance,
    ): Reconciliation {
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
    public function complete(TenantId $tenantId, ReconciliationId $id): Reconciliation
    {
        return $this->connection->transaction(function () use ($tenantId, $id): Reconciliation {
            $current = $this->reconciliationRepository->getByIdForUpdate($tenantId, $id);
            $difference = $this->computeDifference($tenantId, $current);

            if (! $difference->isZero()) {
                throw ReconciliationNotBalancedException::forDifference($id, $difference);
            }

            $reconciliation = $current->complete(new \DateTimeImmutable);
            $this->reconciliationRepository->updateState($reconciliation);

            return $reconciliation;
        });
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

            return $reconciliation;
        });
    }
}
