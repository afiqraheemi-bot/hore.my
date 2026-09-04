<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\NormalBalance;
use App\Domain\Accounting\Journal\Exception\InsufficientJournalLinesException;
use App\Domain\Accounting\Journal\Exception\JournalAlreadyPostedException;
use App\Domain\Accounting\Journal\Exception\MixedCurrencyJournalException;
use App\Domain\Accounting\Journal\Exception\UnbalancedJournalException;
use App\Domain\Accounting\Journal\Journal;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Journal\JournalState;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the ATS-004 tests relevant to {@see Journal} at the
 * construction level this task implements (M3-T5) — the Posting
 * Engine, the Draft -> Posted transition, Reversal/Replacement, and
 * every Actor/Source/Evidence/Audit concern remain out of scope.
 *
 * `JRN-T002`/`JRN-T003`/`JRN-T004` (line-count) and `JRN-T011`
 * (single-Currency) are covered directly and completely, since both
 * are pure construction-time invariants. `JRN-T025`/`JRN-T026` ("a
 * balanced/unbalanced Journal posts successfully/is rejected and
 * never posts") are covered only for their balance-*computation*
 * half — this class validates balance at construction (a choice
 * AETS-004 §9 explicitly permits: "assembled ... and validated in one
 * step"), so a balanced line set is constructible and an unbalanced
 * one is rejected outright; the "Posting Command"/"posts
 * successfully" half of those IDs awaits the future Posting Engine
 * task. `JRN-T075` (the unbalanced golden case) is reproduced exactly
 * as a construction-time rejection.
 *
 * This revision (M3-T6) also covers the Draft -> Posted transition
 * {@see Journal::post()} implements: `JRN-T024` ("attempting to post
 * an already-Posted Journal is rejected with a typed 'already posted'
 * failure") is covered directly and completely, since it is a pure
 * domain state check. `JRN-T023` ("a Posting Command may transition
 * only a Draft Journal to Posted") is covered for its domain-
 * transition half only — this class's `post()` is not itself a
 * Posting Command, so the Tenant/Actor/Evidence/idempotency
 * validation a real Posting Command would also perform (§11) is not
 * exercised here and awaits the future Posting Engine task.
 */
final class JournalTest extends TestCase
{
    private Currency $myr;

    private TenantId $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
        $this->tenantId = TenantId::of('tenant-0001');
    }

    /**
     * A valid Draft Journal is constructible from a balanced,
     * two-line, single-Currency line set.
     */
    public function test_valid_draft_journal_is_constructible(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertInstanceOf(Journal::class, $journal);
    }

    /**
     * A newly constructed Journal always starts Draft — there is no
     * path to any other state from `create()`.
     */
    public function test_journal_starts_draft(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertSame(JournalState::Draft, $journal->state());
    }

    /**
     * A Draft Journal posts successfully.
     */
    public function test_draft_journal_posts_successfully(): void
    {
        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $posted = $draft->post();

        $this->assertInstanceOf(Journal::class, $posted);
        $this->assertSame(JournalState::Posted, $posted->state());
    }

    /**
     * `post()` returns a new instance, distinct from the one it was
     * called on.
     */
    public function test_post_returns_a_new_instance(): void
    {
        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $posted = $draft->post();

        $this->assertNotSame($draft, $posted);
    }

    /**
     * The original Draft Journal `post()` was called on remains Draft
     * — posting never mutates it in place.
     */
    public function test_original_draft_remains_draft_after_posting(): void
    {
        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $draft->post();

        $this->assertSame(JournalState::Draft, $draft->state());
    }

    /**
     * The Posted instance preserves TenantId exactly.
     */
    public function test_posted_preserves_tenant_id(): void
    {
        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $posted = $draft->post();

        $this->assertTrue($this->tenantId->equals($posted->tenantId()));
    }

    /**
     * The Posted instance preserves JournalId exactly.
     */
    public function test_posted_preserves_journal_id(): void
    {
        $id = JournalId::of('journal-0001');
        $draft = Journal::create($this->tenantId, $id, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $posted = $draft->post();

        $this->assertTrue($id->equals($posted->id()));
    }

    /**
     * The Posted instance preserves the exact Journal Line list — same
     * count, same instances (never cloned, never replaced), same
     * order.
     */
    public function test_posted_preserves_exact_journal_line_list(): void
    {
        $debit = $this->debitLine('account-cash', '100.00');
        $credit = $this->creditLine('account-income', '100.00');

        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), [$debit, $credit]);
        $posted = $draft->post();

        $this->assertSame([$debit, $credit], $posted->lines());
    }

    /**
     * The Posted instance remains balanced.
     */
    public function test_posted_remains_balanced(): void
    {
        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $posted = $draft->post();

        $this->assertTrue($posted->isBalanced());
    }

    /**
     * The Posted instance remains single-Currency — every line still
     * carries the same Currency it was constructed with, since posting
     * never touches the line set.
     */
    public function test_posted_remains_single_currency(): void
    {
        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $posted = $draft->post();

        $currencies = array_map(
            static fn (JournalLine $line): string => $line->money()->currency()->identifier(),
            $posted->lines(),
        );

        $this->assertSame(['MYR', 'MYR'], $currencies);
    }

    /**
     * No JournalLine is mutated or replaced during the transition: the
     * Posted instance's lines are the identical objects the Draft
     * instance held, not new or altered ones.
     */
    public function test_journal_lines_remain_unchanged_by_posting(): void
    {
        $debit = $this->debitLine('account-cash', '100.00');
        $credit = $this->creditLine('account-income', '100.00');

        $draft = Journal::create($this->tenantId, JournalId::of('journal-0001'), [$debit, $credit]);
        $posted = $draft->post();

        $this->assertSame($debit, $posted->lines()[0]);
        $this->assertSame($credit, $posted->lines()[1]);
    }

    /**
     * JRN-T024: attempting to post an already-Posted Journal is
     * rejected with a typed "already posted" failure — not silently
     * re-posted, not silently accepted as a no-op.
     */
    public function test_reposting_an_already_posted_journal_is_rejected(): void
    {
        $posted = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ])->post();

        $this->expectException(JournalAlreadyPostedException::class);

        $posted->post();
    }

    /**
     * No Posted -> Draft path exists: no `reopen()`, `draft()`,
     * `unpost()`, `reset()`, `cancel()`, `delete()`, `updateLines()`,
     * or `replaceLines()` method — Posted is terminal.
     */
    public function test_no_posted_to_draft_or_mutation_path_exists(): void
    {
        $reflection = new ReflectionClass(Journal::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach (['reopen', 'draft', 'unpost', 'reset', 'cancel', 'delete', 'updateLines', 'replaceLines'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * JRN-T004: exactly two lines is accepted — the minimum is
     * satisfied.
     */
    public function test_exactly_two_lines_is_accepted(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '50.00'),
            $this->creditLine('account-income', '50.00'),
        ]);

        $this->assertCount(2, $journal->lines());
    }

    /**
     * More than two lines is accepted — no maximum is enforced.
     */
    public function test_more_than_two_lines_is_accepted(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-vat-input', '5.00'),
            $this->creditLine('account-income', '65.00'),
        ]);

        $this->assertCount(3, $journal->lines());
    }

    /**
     * JRN-T002: a Journal with zero Journal Lines is rejected.
     */
    public function test_zero_lines_is_rejected(): void
    {
        $this->expectException(InsufficientJournalLinesException::class);

        Journal::create($this->tenantId, JournalId::of('journal-0001'), []);
    }

    /**
     * JRN-T003: a Journal with exactly one Journal Line is rejected.
     */
    public function test_one_line_is_rejected(): void
    {
        $this->expectException(InsufficientJournalLinesException::class);

        Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
        ]);
    }

    /**
     * A balanced Debit/Credit line set is accepted.
     */
    public function test_balanced_debit_credit_is_accepted(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-expense', '45.50'),
            $this->creditLine('account-cash', '45.50'),
        ]);

        $this->assertTrue($journal->isBalanced());
    }

    /**
     * JRN-T075 (golden case, reproduced at construction time): Debit
     * ACCOUNT-EXPENSE RM45.50, Credit ACCOUNT-CASH RM45.00 — total
     * debit does not equal total credit; rejected, no Journal is ever
     * constructed.
     */
    public function test_unbalanced_journal_is_rejected(): void
    {
        $this->expectException(UnbalancedJournalException::class);

        Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-expense', '45.50'),
            $this->creditLine('account-cash', '45.00'),
        ]);
    }

    /**
     * Balance is exact, via Money's own exact arithmetic — not merely
     * "close": a one-cent difference is rejected just as firmly as a
     * large one.
     */
    public function test_balance_is_exact_not_tolerant(): void
    {
        $this->expectException(UnbalancedJournalException::class);

        Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-expense', '100.00'),
            $this->creditLine('account-cash', '100.01'),
        ]);
    }

    /**
     * Multiple Debit lines balancing multiple Credit lines: total
     * Debit (RM60.00 + RM5.00 = RM65.00) exactly equals total Credit
     * (RM65.00), summed across more than one line per side.
     */
    public function test_multiple_debit_lines_balance_multiple_credit_lines(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '60.00'),
            $this->debitLine('account-vat-input', '5.00'),
            $this->creditLine('account-income', '40.00'),
            $this->creditLine('account-income-2', '25.00'),
        ]);

        $this->assertTrue($journal->isBalanced());
    }

    /**
     * JRN-T011: a mixed-Currency Journal is rejected — even though
     * MYR is the only Currency this codebase's registry currently
     * issues, the cross-currency guard itself is proven directly here
     * with a second, reflection-constructed Currency, mirroring the
     * identical technique already established in Money's own test
     * suite for this same constraint.
     */
    public function test_mixed_currency_is_rejected(): void
    {
        $otherCurrency = $this->currencyOtherThanMyr();

        $this->expectException(MixedCurrencyJournalException::class);

        Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Debit),
            JournalLine::create(AccountId::of('account-income'), Money::fromDecimalString('100.00', $otherCurrency), JournalDirection::Credit),
        ]);
    }

    /**
     * The lines a Journal was constructed with are preserved exactly
     * — same count, same instances, in the same order.
     */
    public function test_lines_are_preserved_exactly(): void
    {
        $debit = $this->debitLine('account-cash', '100.00');
        $credit = $this->creditLine('account-income', '100.00');

        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [$debit, $credit]);

        $this->assertSame([$debit, $credit], $journal->lines());
    }

    /**
     * Mutating the array returned by `lines()` does not affect the
     * Journal's own internal state — a fresh call still returns the
     * original lines, unaffected.
     */
    public function test_returned_lines_array_is_not_externally_mutable(): void
    {
        $debit = $this->debitLine('account-cash', '100.00');
        $credit = $this->creditLine('account-income', '100.00');

        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [$debit, $credit]);

        $lines = $journal->lines();
        $lines[] = $this->creditLine('account-other', '999.00');

        $this->assertCount(2, $journal->lines());
        $this->assertSame([$debit, $credit], $journal->lines());
    }

    /**
     * Journal never mutates the JournalLine objects it was given —
     * each remains the exact same, already-immutable instance
     * ({@see JournalLineTest}
     * already proves JournalLine's own immutability).
     */
    public function test_journal_line_instances_are_never_mutated(): void
    {
        $debit = $this->debitLine('account-cash', '100.00');
        $credit = $this->creditLine('account-income', '100.00');

        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [$debit, $credit]);

        $this->assertSame($debit, $journal->lines()[0]);
        $this->assertSame($credit, $journal->lines()[1]);
    }

    /**
     * TenantId is preserved exactly.
     */
    public function test_tenant_id_is_preserved(): void
    {
        $journal = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertTrue($this->tenantId->equals($journal->tenantId()));
    }

    /**
     * JournalId is preserved exactly.
     */
    public function test_journal_id_is_preserved(): void
    {
        $id = JournalId::of('journal-0001');

        $journal = Journal::create($this->tenantId, $id, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertTrue($id->equals($journal->id()));
    }

    /**
     * Identity equality: two Journals sharing the same JournalId are
     * equal, even if (hypothetically) other fields differed — equals()
     * is identity-based, not value-based.
     */
    public function test_identity_equality_is_based_on_journal_id(): void
    {
        $id = JournalId::of('journal-0001');

        $a = Journal::create($this->tenantId, $id, [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $b = Journal::create($this->tenantId, $id, [
            $this->debitLine('account-cash', '50.00'),
            $this->creditLine('account-income', '50.00'),
        ]);

        $this->assertTrue($a->equals($b));
    }

    /**
     * A different JournalId makes two otherwise-identical Journals
     * unequal.
     */
    public function test_different_journal_id_is_not_equal(): void
    {
        $a = Journal::create($this->tenantId, JournalId::of('journal-0001'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);
        $b = Journal::create($this->tenantId, JournalId::of('journal-0002'), [
            $this->debitLine('account-cash', '100.00'),
            $this->creditLine('account-income', '100.00'),
        ]);

        $this->assertFalse($a->equals($b));
    }

    /**
     * Journal is immutable: every property is readonly and no public
     * mutator method exists.
     */
    public function test_journal_is_immutable(): void
    {
        $reflection = new ReflectionClass(Journal::class);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" must be readonly.', $property->getName()),
            );
        }

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertStringStartsNotWith(
                'set',
                $method->getName(),
                sprintf('Public method "%s" must not be a mutator.', $method->getName()),
            );
        }
    }

    /**
     * No native binary float is used anywhere in balance computation
     * or construction: no `float`-typed parameter, property, or
     * return type appears anywhere in the class.
     */
    public function test_no_binary_float_is_used(): void
    {
        $reflection = new ReflectionClass(Journal::class);

        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType) {
                $this->assertNotSame('float', $type->getName());
            }
        }

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType) {
                    $this->assertNotSame('float', $type->getName());
                }
            }

            $returnType = $method->getReturnType();
            if ($returnType instanceof \ReflectionNamedType) {
                $this->assertNotSame('float', $returnType->getName());
            }
        }
    }

    /**
     * Direction is never inferred from Account Type or Normal
     * Balance: neither type appears anywhere in Journal's own
     * executable declaration.
     */
    public function test_has_no_account_type_or_normal_balance_dependency(): void
    {
        $reflection = new ReflectionClass(Journal::class);
        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);

        $classBodyStart = strpos($source, 'final class Journal');
        $this->assertIsInt($classBodyStart);

        $classBody = substr($source, $classBodyStart);

        $this->assertStringNotContainsString('AccountType', $classBody);
        $this->assertStringNotContainsString('NormalBalance', $classBody);
    }

    /**
     * JournalDirection and NormalBalance remain separate types.
     */
    public function test_journal_direction_is_a_distinct_type_from_normal_balance(): void
    {
        $this->assertNotSame(NormalBalance::class, JournalDirection::class);
    }

    /**
     * The only path to a Posted Journal is `post()` itself — no
     * alternately-named posting method exists, and no other public
     * method beyond the fixed, minimal, intended API (which now
     * includes `post()`, per M3-T6).
     */
    public function test_only_the_intended_public_api_including_post_exists(): void
    {
        $reflection = new ReflectionClass(Journal::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($publicMethodNames);

        $this->assertSame(
            ['create', 'equals', 'id', 'isBalanced', 'lines', 'post', 'state', 'tenantId'],
            $publicMethodNames,
        );

        foreach (['postJournal', 'transition', 'markPosted'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * `create()` accepts no JournalState argument at all — the caller
     * cannot supply one, so a Posted Journal can never be requested
     * directly.
     */
    public function test_create_accepts_no_journal_state_argument(): void
    {
        $method = new \ReflectionMethod(Journal::class, 'create');

        $parameterTypes = array_map(
            static fn (\ReflectionParameter $parameter): ?string => $parameter->getType() instanceof \ReflectionNamedType
                ? $parameter->getType()->getName()
                : null,
            $method->getParameters(),
        );

        $this->assertNotContains(JournalState::class, $parameterTypes);
    }

    /**
     * No repository, database, or persistence dependency: Journal
     * exposes only its own aggregate API, never extends or implements
     * any database-related type, and has no Illuminate/Eloquent
     * dependency anywhere in the file.
     */
    public function test_has_no_framework_or_database_dependency(): void
    {
        $reflection = new ReflectionClass(Journal::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());

        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    private function debitLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Debit);
    }

    private function creditLine(string $accountId, string $amount): JournalLine
    {
        return JournalLine::create(AccountId::of($accountId), Money::fromDecimalString($amount, $this->myr), JournalDirection::Credit);
    }

    /**
     * Mirrors the identical reflection-based workaround already
     * established in `MoneyTest::currencyOtherThanMyr()`, since MYR is
     * currently the only Currency this codebase's registry issues.
     */
    private function currencyOtherThanMyr(): Currency
    {
        $reflection = new ReflectionClass(Currency::class);
        $other = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('identifier')->setValue($other, 'XXX');
        $reflection->getProperty('scale')->setValue($other, 2);

        return $other;
    }
}
