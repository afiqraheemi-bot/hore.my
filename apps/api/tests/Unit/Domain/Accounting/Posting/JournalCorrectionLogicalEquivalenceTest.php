<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Posting;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Posting\JournalCorrectionLogicalEquivalence;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Covers `JournalCorrectionLogicalEquivalence` — the pure, storage-free
 * comparison rule M5's architecture decision (gap A) defines for
 * distinguishing a safe correction replay from a conflicting reuse,
 * mirroring {@see PostingCommandLogicalEquivalenceTest}'s own coverage
 * of the M4 comparator this class is a deliberate sibling of.
 *
 * **The scenario gap A exists specifically to catch.** A Reversal and
 * a Replacement can, in principle, produce coincidentally identical
 * Journal Lines — {@see test_same_lines_but_different_correction_type_is_not_equivalent()}
 * proves that alone is never enough to be judged a safe replay: the
 * CorrectionType itself must also agree.
 */
final class JournalCorrectionLogicalEquivalenceTest extends TestCase
{
    private JournalCorrectionLogicalEquivalence $equivalence;

    private TenantId $tenantA;

    private TenantId $tenantB;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->equivalence = new JournalCorrectionLogicalEquivalence;
        $this->tenantA = TenantId::of('tenant-0001');
        $this->tenantB = TenantId::of('tenant-0002');
        $this->myr = Currency::of('MYR');
    }

    public function test_identical_reversal_candidates_are_equivalent(): void
    {
        $original = $this->postedOriginal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $left = $original->reverse(JournalId::of('journal-reversal'), $this->financialDate());
        $right = $original->reverse(JournalId::of('journal-reversal'), $this->financialDate());

        $this->assertTrue($this->equivalence->equivalent($left, $right));
    }

    public function test_different_tenant_is_not_equivalent(): void
    {
        $originalA = $this->postedOriginal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $originalB = $this->postedOriginal($this->tenantB, JournalId::of('journal-original'), $this->balancedLines());

        $left = $originalA->reverse(JournalId::of('journal-reversal'), $this->financialDate());
        $right = $originalB->reverse(JournalId::of('journal-reversal'), $this->financialDate());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    public function test_different_new_journal_id_is_not_equivalent(): void
    {
        $original = $this->postedOriginal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $left = $original->reverse(JournalId::of('journal-reversal-one'), $this->financialDate());
        $right = $original->reverse(JournalId::of('journal-reversal-two'), $this->financialDate());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * (M8) A Reversal candidate reusing the same (Tenant, Idempotency
     * Key) but supplied with a different Financial Date is a
     * conflicting reuse, not a safe replay.
     */
    public function test_different_financial_date_is_not_equivalent(): void
    {
        $original = $this->postedOriginal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());

        $left = $original->reverse(JournalId::of('journal-reversal'), new \DateTimeImmutable('2026-01-01'));
        $right = $original->reverse(JournalId::of('journal-reversal'), new \DateTimeImmutable('2026-02-01'));

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    public function test_different_corrected_journal_id_is_not_equivalent(): void
    {
        $originalOne = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-one'), $this->balancedLines());
        $originalTwo = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-two'), $this->balancedLines());

        $left = $originalOne->reverse(JournalId::of('journal-reversal'), $this->financialDate());
        $right = $originalTwo->reverse(JournalId::of('journal-reversal'), $this->financialDate());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    /**
     * The gap-A scenario: a Reversal and a Replacement can
     * coincidentally assemble the exact same Journal Lines (here, a
     * Replacement deliberately supplied with the Reversal's own flipped
     * lines) while being entirely different correction requests — a
     * different CorrectionType and a different corrected-Journal
     * reference. Coincidentally-identical lines must never be enough to
     * be judged a safe replay on their own.
     */
    public function test_same_lines_but_different_correction_type_is_not_equivalent(): void
    {
        $original = $this->postedOriginal($this->tenantA, JournalId::of('journal-original'), $this->balancedLines());
        $reversal = $original->reverse(JournalId::of('journal-reversal'), $this->financialDate())->post($this->postedAt());

        $newJournalId = JournalId::of('journal-shared-id');

        // A Reversal of $original produces flipped lines; a Replacement
        // deliberately supplied with those same flipped lines produces
        // a coincidentally identical Journal Line set under a different
        // CorrectionType and a different corrected-Journal reference.
        $flippedLines = $original->reverse(JournalId::of('journal-reversal-throwaway'), $this->financialDate())->lines();

        $left = $original->reverse($newJournalId, $this->financialDate());
        $right = Journal::createReplacement($this->tenantA, $newJournalId, $flippedLines, $reversal, $this->financialDate());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    public function test_different_line_count_is_not_equivalent(): void
    {
        $originalTwoLines = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-a'), $this->balancedLines());
        $originalThreeLines = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-b'), [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-other', '40.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $left = $originalTwoLines->reverse(JournalId::of('journal-reversal'), $this->financialDate());
        $right = $originalThreeLines->reverse(JournalId::of('journal-reversal'), $this->financialDate());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    public function test_reordered_lines_are_not_equivalent(): void
    {
        $reversalId = JournalId::of('journal-reversal');
        $originalOrderA = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-a'), [
            $this->debitLine('account-cash', '10.00'),
            $this->debitLine('account-other', '20.00'),
            $this->creditLine('account-income', '30.00'),
        ]);
        $originalOrderB = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-b'), [
            $this->debitLine('account-other', '20.00'),
            $this->debitLine('account-cash', '10.00'),
            $this->creditLine('account-income', '30.00'),
        ]);

        $left = $originalOrderA->reverse($reversalId, $this->financialDate());
        $right = $originalOrderB->reverse($reversalId, $this->financialDate());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    public function test_account_id_difference_is_not_equivalent(): void
    {
        $reversalId = JournalId::of('journal-reversal');
        $originalOne = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-a'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $originalTwo = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-b'), [
            $this->debitLine('account-other', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $left = $originalOne->reverse($reversalId, $this->financialDate());
        $right = $originalTwo->reverse($reversalId, $this->financialDate());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    public function test_amount_difference_is_not_equivalent(): void
    {
        $reversalId = JournalId::of('journal-reversal');
        $originalOne = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-a'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $originalTwo = $this->postedOriginal($this->tenantA, JournalId::of('journal-original-b'), [
            $this->debitLine('account-cash', '150.00'),
            $this->creditLine('account-income', '150.00'),
        ]);

        $left = $originalOne->reverse($reversalId, $this->financialDate());
        $right = $originalTwo->reverse($reversalId, $this->financialDate());

        $this->assertFalse($this->equivalence->equivalent($left, $right));
    }

    private function financialDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-15');
    }

    private function postedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-06 10:00:00');
    }

    /**
     * @return list<JournalLine>
     */
    private function balancedLines(): array
    {
        return [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ];
    }

    /**
     * @param  list<JournalLine>  $lines
     */
    private function postedOriginal(TenantId $tenantId, JournalId $journalId, array $lines): Journal
    {
        return Journal::create($tenantId, $journalId, $lines, $this->financialDate())->post($this->postedAt());
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }
}
