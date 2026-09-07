<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Banking;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\BankAccountId;
use App\Domain\Banking\Exception\InvalidReconciliationPeriodException;
use App\Domain\Banking\Exception\InvalidReconciliationStateTransitionException;
use App\Domain\Banking\Reconciliation;
use App\Domain\Banking\ReconciliationDifference;
use App\Domain\Banking\ReconciliationId;
use App\Domain\Banking\ReconciliationService;
use App\Domain\Banking\ReconciliationState;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Covers the `Reconciliation` aggregate's own state-machine invariants
 * (M18, SRS §10.4: Draft -> In Review -> Balanced -> Completed, reopen
 * returns to Draft) — purely at the entity level; the *data*
 * precondition for `markBalanced()` (a zero
 * {@see ReconciliationDifference}) is
 * {@see ReconciliationService}'s own concern,
 * covered separately.
 */
final class ReconciliationTest extends TestCase
{
    private function open(): Reconciliation
    {
        $myr = Currency::of('MYR');

        return Reconciliation::open(
            ReconciliationId::of('reconciliation-0001'),
            TenantId::of('tenant-0001'),
            BankAccountId::of('bank-account-0001'),
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-31'),
            Money::fromDecimalString('1000.00', $myr),
            Money::fromDecimalString('1500.00', $myr),
            new \DateTimeImmutable('2026-09-01'),
        );
    }

    public function test_opens_in_draft_state(): void
    {
        $this->assertSame(ReconciliationState::Draft, $this->open()->state());
    }

    public function test_period_end_before_start_is_rejected(): void
    {
        $this->expectException(InvalidReconciliationPeriodException::class);

        Reconciliation::open(
            ReconciliationId::of('reconciliation-0001'),
            TenantId::of('tenant-0001'),
            BankAccountId::of('bank-account-0001'),
            new \DateTimeImmutable('2026-08-31'),
            new \DateTimeImmutable('2026-08-01'),
            Money::fromDecimalString('1000.00', Currency::of('MYR')),
            Money::fromDecimalString('1500.00', Currency::of('MYR')),
            new \DateTimeImmutable('2026-09-01'),
        );
    }

    public function test_period_end_equal_to_start_is_accepted(): void
    {
        $myr = Currency::of('MYR');

        $reconciliation = Reconciliation::open(
            ReconciliationId::of('reconciliation-0001'),
            TenantId::of('tenant-0001'),
            BankAccountId::of('bank-account-0001'),
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-01'),
            Money::fromDecimalString('1000.00', $myr),
            Money::fromDecimalString('1000.00', $myr),
            new \DateTimeImmutable('2026-09-01'),
        );

        $this->assertSame(ReconciliationState::Draft, $reconciliation->state());
    }

    public function test_full_happy_path_lifecycle(): void
    {
        $reconciliation = $this->open()->startReview()->markBalanced()->complete(new \DateTimeImmutable('2026-09-01'));

        $this->assertSame(ReconciliationState::Completed, $reconciliation->state());
        $this->assertNotNull($reconciliation->completedAt());

        $reopened = $reconciliation->reopen();
        $this->assertSame(ReconciliationState::Draft, $reopened->state());
        $this->assertNull($reopened->completedAt());
    }

    public function test_start_review_only_valid_from_draft(): void
    {
        $inReview = $this->open()->startReview();

        $this->expectException(InvalidReconciliationStateTransitionException::class);

        $inReview->startReview();
    }

    public function test_mark_balanced_only_valid_from_in_review(): void
    {
        $this->expectException(InvalidReconciliationStateTransitionException::class);

        $this->open()->markBalanced();
    }

    public function test_complete_only_valid_from_balanced(): void
    {
        $this->expectException(InvalidReconciliationStateTransitionException::class);

        $this->open()->startReview()->complete(new \DateTimeImmutable);
    }

    public function test_reopen_only_valid_from_completed(): void
    {
        $this->expectException(InvalidReconciliationStateTransitionException::class);

        $this->open()->startReview()->markBalanced()->reopen();
    }

    public function test_reopened_reconciliation_can_advance_through_the_lifecycle_again(): void
    {
        $reconciliation = $this->open()->startReview()->markBalanced()->complete(new \DateTimeImmutable)->reopen();

        $advanced = $reconciliation->startReview()->markBalanced()->complete(new \DateTimeImmutable);

        $this->assertSame(ReconciliationState::Completed, $advanced->state());
    }

    public function test_transitions_never_mutate_the_original_instance(): void
    {
        $draft = $this->open();
        $draft->startReview();

        $this->assertSame(ReconciliationState::Draft, $draft->state());
    }

    public function test_equals_is_identity_based(): void
    {
        $a = $this->open();
        $b = $a->startReview();

        $this->assertTrue($a->equals($b));
    }
}
