<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\Exception\InvalidReconciliationPeriodException;
use App\Domain\Banking\Exception\InvalidReconciliationStateTransitionException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Reconciliation aggregate (M18, SRS BNK-006/BNK-007, §10.4) — a
 * period against one BankAccount, its user-stated opening/closing
 * balance, and its state through Draft -> In Review -> Balanced ->
 * Completed (see {@see ReconciliationState}'s own docblock).
 *
 * **Immutable — every transition returns a new instance**, mirroring
 * this codebase's established "no in-place mutation" convention
 * (`BankTransaction`, `Journal`'s own Draft/Posted distinction). Only
 * {@see ReconciliationState}-level validity is enforced here (can this
 * transition happen from this state at all); the *data*-level
 * precondition for `Draft`/`InReview` -> `Balanced` (a zero
 * {@see ReconciliationDifference}) is {@see ReconciliationService}'s
 * concern, not this class's — it has no access to BankTransaction data
 * to compute one itself, mirroring how `Account` Type validation lives
 * in a separate validator, not in `Account` itself.
 */
final class Reconciliation
{
    private function __construct(
        private readonly ReconciliationId $id,
        private readonly TenantId $tenantId,
        private readonly BankAccountId $bankAccountId,
        private readonly \DateTimeImmutable $periodStart,
        private readonly \DateTimeImmutable $periodEnd,
        private readonly Money $openingBalance,
        private readonly Money $closingBalance,
        private readonly ReconciliationState $state,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?\DateTimeImmutable $completedAt,
    ) {}

    /**
     * @throws InvalidReconciliationPeriodException if `$periodEnd` is
     *                                              before `$periodStart`.
     */
    public static function open(
        ReconciliationId $id,
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        Money $openingBalance,
        Money $closingBalance,
        \DateTimeImmutable $createdAt,
    ): self {
        if ($periodEnd < $periodStart) {
            throw InvalidReconciliationPeriodException::forEndBeforeStart();
        }

        return new self($id, $tenantId, $bankAccountId, $periodStart, $periodEnd, $openingBalance, $closingBalance, ReconciliationState::Draft, $createdAt, null);
    }

    public static function reconstitute(
        ReconciliationId $id,
        TenantId $tenantId,
        BankAccountId $bankAccountId,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        Money $openingBalance,
        Money $closingBalance,
        ReconciliationState $state,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $completedAt,
    ): self {
        return new self($id, $tenantId, $bankAccountId, $periodStart, $periodEnd, $openingBalance, $closingBalance, $state, $createdAt, $completedAt);
    }

    /**
     * @throws InvalidReconciliationStateTransitionException unless the
     *                                                       current state is Draft.
     */
    public function startReview(): self
    {
        if ($this->state !== ReconciliationState::Draft) {
            throw InvalidReconciliationStateTransitionException::forTransition($this->id, $this->state, 'start review');
        }

        return new self($this->id, $this->tenantId, $this->bankAccountId, $this->periodStart, $this->periodEnd, $this->openingBalance, $this->closingBalance, ReconciliationState::InReview, $this->createdAt, $this->completedAt);
    }

    /**
     * @throws InvalidReconciliationStateTransitionException unless the
     *                                                       current state is InReview.
     */
    public function markBalanced(): self
    {
        if ($this->state !== ReconciliationState::InReview) {
            throw InvalidReconciliationStateTransitionException::forTransition($this->id, $this->state, 'mark balanced');
        }

        return new self($this->id, $this->tenantId, $this->bankAccountId, $this->periodStart, $this->periodEnd, $this->openingBalance, $this->closingBalance, ReconciliationState::Balanced, $this->createdAt, $this->completedAt);
    }

    /**
     * @throws InvalidReconciliationStateTransitionException unless the
     *                                                       current state is Balanced.
     */
    public function complete(\DateTimeImmutable $completedAt): self
    {
        if ($this->state !== ReconciliationState::Balanced) {
            throw InvalidReconciliationStateTransitionException::forTransition($this->id, $this->state, 'complete');
        }

        return new self($this->id, $this->tenantId, $this->bankAccountId, $this->periodStart, $this->periodEnd, $this->openingBalance, $this->closingBalance, ReconciliationState::Completed, $this->createdAt, $completedAt);
    }

    /**
     * @throws InvalidReconciliationStateTransitionException unless the
     *                                                       current state is Completed.
     */
    public function reopen(): self
    {
        if ($this->state !== ReconciliationState::Completed) {
            throw InvalidReconciliationStateTransitionException::forTransition($this->id, $this->state, 'reopen');
        }

        return new self($this->id, $this->tenantId, $this->bankAccountId, $this->periodStart, $this->periodEnd, $this->openingBalance, $this->closingBalance, ReconciliationState::Draft, $this->createdAt, null);
    }

    public function id(): ReconciliationId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function bankAccountId(): BankAccountId
    {
        return $this->bankAccountId;
    }

    public function periodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function periodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function openingBalance(): Money
    {
        return $this->openingBalance;
    }

    public function closingBalance(): Money
    {
        return $this->closingBalance;
    }

    public function state(): ReconciliationState
    {
        return $this->state;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }
}
