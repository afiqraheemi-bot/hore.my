<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\CorrectionType;
use App\Domain\Accounting\Journal\Exception\InconsistentCorrectionMetadataException;
use App\Domain\Accounting\Journal\Exception\InvalidReplacementTargetException;
use App\Domain\Accounting\Journal\Exception\InvalidReversalTargetException;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers {@see Journal::reverse()} and {@see Journal::createReplacement()}
 * (M5, AETS-004 §16, §17) — pure domain behavior only, no persistence,
 * no repository, no idempotency. Real-PostgreSQL persistence,
 * concurrency, and end-to-end atomicity are covered separately by
 * `JournalCorrectionRepositoryIntegrationTest`/
 * `JournalCorrectionTransactionalExecutorTest`.
 *
 * An ordinary Journal (`correctionType() === null`) is already fully
 * covered by `JournalTest` and is not re-verified here.
 */
final class JournalCorrectionTest extends TestCase
{
    private TenantId $tenantId;

    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = TenantId::of('tenant-0001');
        $this->myr = Currency::of('MYR');
    }

    // --- Reversal: neutrality (JRN-018) ------------------------------------

    public function test_reverse_produces_lines_with_opposite_direction_same_account_and_money(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ], $this->financialDate())->post($this->postedAt());

        $reversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate());

        $this->assertCount(2, $reversal->lines());
        $this->assertTrue($reversal->lines()[0]->accountId()->equals(AccountId::of('account-cash')));
        $this->assertSame(JournalDirection::Credit, $reversal->lines()[0]->direction());
        $this->assertTrue($reversal->lines()[0]->money()->equals($original->lines()[0]->money()));
        $this->assertTrue($reversal->lines()[1]->accountId()->equals(AccountId::of('account-income')));
        $this->assertSame(JournalDirection::Debit, $reversal->lines()[1]->direction());
        $this->assertTrue($reversal->lines()[1]->money()->equals($original->lines()[1]->money()));
    }

    /**
     * Combined net effect per Account, across the original and its
     * Reversal, is exactly zero — proven by construction: every
     * Reversal line has the identical Money magnitude as its
     * corresponding original line, with Direction flipped, so summing
     * both directions for the same Account always cancels exactly.
     * This is `JRN-018` itself, not an approximation of it.
     */
    #[DataProvider('representativeJournalLineSets')]
    public function test_reversal_neutralizes_every_line_exactly(array $lineSpecs): void
    {
        $lines = array_map(
            fn (array $spec): JournalLine => JournalLine::create(
                AccountId::of($spec['account']),
                Money::fromDecimalString($spec['amount'], $this->myr),
                $spec['direction'],
            ),
            $lineSpecs,
        );

        $original = Journal::create($this->tenantId, JournalId::of('journal-original'), $lines, $this->financialDate())->post($this->postedAt());
        $reversal = $original->reverse(JournalId::of('journal-reversal'), $this->financialDate());

        $this->assertCount(count($lines), $reversal->lines());

        foreach ($lines as $index => $originalLine) {
            $reversalLine = $reversal->lines()[$index];

            $this->assertTrue($reversalLine->accountId()->equals($originalLine->accountId()));
            $this->assertTrue($reversalLine->money()->equals($originalLine->money()));
            $this->assertNotSame($originalLine->direction(), $reversalLine->direction());
        }

        // The Reversal itself is exactly balanced (guaranteed already by
        // Journal::create()'s own invariant, reconfirmed here as the
        // direct, observable consequence of neutralizing a balanced
        // original one line at a time).
        $this->assertTrue($reversal->isBalanced());
    }

    /**
     * A representative range of balanced Journal Line sets — varying
     * line count, amount, and Account — the smallest available
     * technique for "proven across a generated range of inputs" in
     * this codebase, since no property-based testing library is
     * decided project-wide (ATS-003/004/005 §Deferred Items).
     *
     * @return array<string, array{list<array{account: string, amount: string, direction: JournalDirection}>}>
     */
    public static function representativeJournalLineSets(): array
    {
        return [
            'two lines, round amount' => [[
                ['account' => 'account-cash', 'amount' => '100.00', 'direction' => JournalDirection::Debit],
                ['account' => 'account-income', 'amount' => '100.00', 'direction' => JournalDirection::Credit],
            ]],
            'two lines, exact minor unit' => [[
                ['account' => 'account-cash', 'amount' => '0.01', 'direction' => JournalDirection::Debit],
                ['account' => 'account-income', 'amount' => '0.01', 'direction' => JournalDirection::Credit],
            ]],
            'three lines, split debit' => [[
                ['account' => 'account-cash', 'amount' => '60.00', 'direction' => JournalDirection::Debit],
                ['account' => 'account-other', 'amount' => '40.00', 'direction' => JournalDirection::Debit],
                ['account' => 'account-income', 'amount' => '100.00', 'direction' => JournalDirection::Credit],
            ]],
            'four lines, mixed split' => [[
                ['account' => 'account-cash', 'amount' => '25.50', 'direction' => JournalDirection::Debit],
                ['account' => 'account-other', 'amount' => '74.50', 'direction' => JournalDirection::Debit],
                ['account' => 'account-income', 'amount' => '30.00', 'direction' => JournalDirection::Credit],
                ['account' => 'account-expense', 'amount' => '70.00', 'direction' => JournalDirection::Credit],
            ]],
            'large exact amount' => [[
                ['account' => 'account-cash', 'amount' => '999999.99', 'direction' => JournalDirection::Debit],
                ['account' => 'account-income', 'amount' => '999999.99', 'direction' => JournalDirection::Credit],
            ]],
        ];
    }

    public function test_reverse_preserves_tenant_and_sets_correction_metadata(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());

        $reversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate());

        $this->assertTrue($reversal->tenantId()->equals($this->tenantId));
        $this->assertSame(CorrectionType::Reversal, $reversal->correctionType());
        $this->assertNotNull($reversal->correctedJournalId());
        $this->assertTrue($reversal->correctedJournalId()->equals($original->id()));
    }

    public function test_reverse_result_is_draft_until_posted(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());

        $reversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate());

        $this->assertSame(JournalState::Draft, $reversal->state());
    }

    public function test_original_journal_is_completely_unaffected_by_reversal(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());
        $before = $original;

        $original->reverse(JournalId::of('journal-0002'), $this->financialDate());

        $this->assertTrue($before->equals($original));
        $this->assertSame(JournalState::Posted, $original->state());
        $this->assertNull($original->correctionType());
        $this->assertCount(2, $original->lines());
    }

    // --- Reversal: invalid targets ------------------------------------------

    public function test_reversing_a_draft_journal_is_rejected(): void
    {
        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate());

        $this->expectException(InvalidReversalTargetException::class);

        $draft->reverse(JournalId::of('journal-0002'), $this->financialDate());
    }

    public function test_reversing_a_reversal_is_rejected(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());
        $reversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate())->post($this->postedAt());

        $this->expectException(InvalidReversalTargetException::class);

        $reversal->reverse(JournalId::of('journal-0003'), $this->financialDate());
    }

    public function test_reversing_a_replacement_is_rejected(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());
        $reversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate())->post($this->postedAt());
        $replacement = Journal::createReplacement(
            $this->tenantId,
            JournalId::of('journal-0003'),
            $this->balancedLines(),
            $reversal,
            $this->financialDate(),
        )->post($this->postedAt());

        $this->expectException(InvalidReversalTargetException::class);

        $replacement->reverse(JournalId::of('journal-0004'), $this->financialDate());
    }

    // --- Replacement: chain construction -------------------------------------

    public function test_create_replacement_references_the_reversal_and_carries_caller_lines(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());
        $reversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate())->post($this->postedAt());

        $correctedLines = [
            $this->debitLine('account-cash', '120.00'),
            $this->creditLine('account-income', '120.00'),
        ];
        $replacement = Journal::createReplacement($this->tenantId, JournalId::of('journal-0003'), $correctedLines, $reversal, $this->financialDate());

        $this->assertSame(CorrectionType::Replacement, $replacement->correctionType());
        $this->assertTrue($replacement->correctedJournalId()->equals($reversal->id()));
        $this->assertTrue($replacement->lines()[0]->money()->equals($correctedLines[0]->money()));
        $this->assertSame(JournalState::Draft, $replacement->state());
    }

    /**
     * Original -> Reversal -> Replacement is traceable in one
     * unambiguous direction (AETS-004 §17) — proven by walking the
     * chain purely through in-memory references, no persistence
     * required for this half of the guarantee.
     */
    public function test_replacement_to_reversal_to_original_chain_is_traceable(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());
        $reversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate())->post($this->postedAt());
        $replacement = Journal::createReplacement(
            $this->tenantId,
            JournalId::of('journal-0003'),
            $this->balancedLines(),
            $reversal,
            $this->financialDate(),
        );

        $this->assertTrue($replacement->correctedJournalId()->equals($reversal->id()));
        $this->assertTrue($reversal->correctedJournalId()->equals($original->id()));
        $this->assertNull($original->correctedJournalId());
    }

    // --- Replacement: invalid targets ----------------------------------------

    public function test_replacement_referencing_an_original_journal_directly_is_rejected(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());

        $this->expectException(InvalidReplacementTargetException::class);

        Journal::createReplacement($this->tenantId, JournalId::of('journal-0002'), $this->balancedLines(), $original, $this->financialDate());
    }

    public function test_replacement_referencing_another_replacement_is_rejected(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());
        $reversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate())->post($this->postedAt());
        $replacement = Journal::createReplacement(
            $this->tenantId,
            JournalId::of('journal-0003'),
            $this->balancedLines(),
            $reversal,
            $this->financialDate(),
        )->post($this->postedAt());

        $this->expectException(InvalidReplacementTargetException::class);

        Journal::createReplacement($this->tenantId, JournalId::of('journal-0004'), $this->balancedLines(), $replacement, $this->financialDate());
    }

    public function test_replacement_referencing_a_draft_reversal_is_rejected(): void
    {
        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());
        $draftReversal = $original->reverse(JournalId::of('journal-0002'), $this->financialDate());

        $this->expectException(InvalidReplacementTargetException::class);

        Journal::createReplacement($this->tenantId, JournalId::of('journal-0003'), $this->balancedLines(), $draftReversal, $this->financialDate());
    }

    // --- Consistency invariant -------------------------------------------------

    public function test_reconstitute_rejects_correction_type_without_corrected_journal_id(): void
    {
        $this->expectException(InconsistentCorrectionMetadataException::class);

        Journal::reconstitute(
            $this->tenantId,
            JournalId::of('journal-0001'),
            $this->balancedLines(),
            JournalState::Posted,
            $this->financialDate(),
            CorrectionType::Reversal,
            null,
        );
    }

    public function test_reconstitute_rejects_corrected_journal_id_without_correction_type(): void
    {
        $this->expectException(InconsistentCorrectionMetadataException::class);

        Journal::reconstitute(
            $this->tenantId,
            JournalId::of('journal-0001'),
            $this->balancedLines(),
            JournalState::Posted,
            $this->financialDate(),
            null,
            JournalId::of('journal-original'),
        );
    }

    public function test_reconstitute_accepts_both_present_together(): void
    {
        $journal = Journal::reconstitute(
            $this->tenantId,
            JournalId::of('journal-0002'),
            $this->balancedLines(),
            JournalState::Posted,
            $this->financialDate(),
            CorrectionType::Reversal,
            JournalId::of('journal-0001'),
            $this->postedAt(),
        );

        $this->assertSame(CorrectionType::Reversal, $journal->correctionType());
        $this->assertTrue($journal->correctedJournalId()->equals(JournalId::of('journal-0001')));
    }

    public function test_create_leaves_ordinary_journal_with_no_correction_metadata(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate());

        $this->assertNull($journal->correctionType());
        $this->assertNull($journal->correctedJournalId());
    }

    // --- Purity: reverse() never generates its own JournalId -------------------

    /**
     * `reverse()` accepts the new JournalId as a parameter and never
     * generates one internally — mirroring the same convention already
     * established for {@see Journal::create()} (AETS-004 §11: no
     * self-generation policy exists for `JournalId` in this codebase).
     * It also requires an explicit Financial Date (M8) — never derived
     * from the original Journal it reverses.
     */
    public function test_reverse_requires_the_new_journal_id_and_financial_date_as_parameters(): void
    {
        $reflection = new ReflectionClass(Journal::class);
        $method = $reflection->getMethod('reverse');

        $this->assertCount(2, $method->getParameters());
        $this->assertSame('App\Domain\Accounting\Journal\JournalId', (string) $method->getParameters()[0]->getType());
        $this->assertSame('DateTimeImmutable', (string) $method->getParameters()[1]->getType());
    }

    // --- Financial date: caller-supplied, never derived from the corrected Journal (M8) ---

    /**
     * A Reversal carries exactly the Financial Date its caller
     * supplied — never the original Journal's own Financial Date, even
     * though this test deliberately supplies a different one.
     */
    public function test_reversal_carries_its_own_supplied_financial_date_not_the_originals(): void
    {
        $originalFinancialDate = new \DateTimeImmutable('2026-01-10');
        $reversalFinancialDate = new \DateTimeImmutable('2026-03-20');

        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $originalFinancialDate)->post($this->postedAt());

        $reversal = $original->reverse(JournalId::of('journal-0002'), $reversalFinancialDate);

        $this->assertSame($reversalFinancialDate, $reversal->financialDate());
        $this->assertNotEquals($originalFinancialDate, $reversal->financialDate());
    }

    /**
     * A Replacement carries exactly the Financial Date its caller
     * supplied — never the Reversal's own Financial Date.
     */
    public function test_replacement_carries_its_own_supplied_financial_date_not_the_reversals(): void
    {
        $reversalFinancialDate = new \DateTimeImmutable('2026-03-20');
        $replacementFinancialDate = new \DateTimeImmutable('2026-04-01');

        $original = Journal::create($this->tenantId, JournalId::of('journal-0001'), $this->balancedLines(), $this->financialDate())->post($this->postedAt());
        $reversal = $original->reverse(JournalId::of('journal-0002'), $reversalFinancialDate)->post($this->postedAt());

        $replacement = Journal::createReplacement($this->tenantId, JournalId::of('journal-0003'), $this->balancedLines(), $reversal, $replacementFinancialDate);

        $this->assertSame($replacementFinancialDate, $replacement->financialDate());
        $this->assertNotEquals($reversalFinancialDate, $replacement->financialDate());
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

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }
}
