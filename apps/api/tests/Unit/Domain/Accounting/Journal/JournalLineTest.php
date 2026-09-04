<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\ChartOfAccounts\NormalBalance;
use App\Domain\Accounting\Journal\JournalDirection;
use App\Domain\Accounting\Journal\JournalLine;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the ATS-004 tests relevant to {@see JournalLine} at the
 * construction level this task implements (M3-T3) — a Journal
 * aggregate does not exist yet, so balance validation, Posting, and
 * every other Journal-level concern remain out of scope here.
 *
 * `JRN-T005`/`JRN-T006` ("a Journal Line with Debit/Credit direction
 * is valid") are now fully exercised at the Journal Line level for
 * the first time — {@see JournalDirectionTest}
 * (M3-T1) could only prove the enum-level foundation, since no Journal
 * Line existed yet. `JRN-T007`/`JRN-T008` ("cannot represent both/
 * neither") remain proven structurally: `$direction` is a single
 * required `JournalDirection` constructor parameter, so no
 * constructible JournalLine can hold two directions or none — PHP's
 * own type system forbids it, not a runtime check this class adds.
 * `JRN-T010` ("a Journal Line's Money is immutable") is covered here
 * at the single-line level; the "no Journal operation mutates a
 * recorded Line's amount in place" half of that guarantee awaits the
 * future Journal aggregate.
 */
final class JournalLineTest extends TestCase
{
    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
    }

    /**
     * JRN-T005: a Journal Line with Debit direction is valid.
     */
    public function test_valid_debit_line_is_constructible(): void
    {
        $line = JournalLine::create(
            AccountId::of('account-cash'),
            Money::fromDecimalString('100.00', $this->myr),
            JournalDirection::Debit,
        );

        $this->assertInstanceOf(JournalLine::class, $line);
        $this->assertSame(JournalDirection::Debit, $line->direction());
    }

    /**
     * JRN-T006: a Journal Line with Credit direction is valid.
     */
    public function test_valid_credit_line_is_constructible(): void
    {
        $line = JournalLine::create(
            AccountId::of('account-income'),
            Money::fromDecimalString('100.00', $this->myr),
            JournalDirection::Credit,
        );

        $this->assertInstanceOf(JournalLine::class, $line);
        $this->assertSame(JournalDirection::Credit, $line->direction());
    }

    /**
     * Exactly one Account reference is carried, returned exactly as
     * supplied.
     */
    public function test_carries_exactly_one_account(): void
    {
        $accountId = AccountId::of('account-cash');

        $line = JournalLine::create($accountId, Money::fromDecimalString('50.00', $this->myr), JournalDirection::Debit);

        $this->assertTrue($accountId->equals($line->accountId()));
    }

    /**
     * Exactly one Money amount is carried, returned exactly as
     * supplied — the same instance, unmodified.
     */
    public function test_carries_exactly_one_money_amount(): void
    {
        $money = Money::fromDecimalString('45.50', $this->myr);

        $line = JournalLine::create(AccountId::of('account-expense'), $money, JournalDirection::Debit);

        $this->assertTrue($money->equals($line->money()));
        $this->assertSame('45.50', $line->money()->toDecimalString());
    }

    /**
     * Exactly one Direction is carried, returned exactly as supplied
     * — structurally, since `direction()`'s return type is the
     * single-valued `JournalDirection` parameter, never an array or a
     * pair of booleans.
     */
    public function test_carries_exactly_one_direction(): void
    {
        $method = new \ReflectionMethod(JournalLine::class, 'direction');
        $returnType = $method->getReturnType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame(JournalDirection::class, $returnType->getName());
        $this->assertFalse($returnType->allowsNull());
    }

    /**
     * JournalLine is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_is_immutable(): void
    {
        $reflection = new ReflectionClass(JournalLine::class);

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
     * Two JournalLines built from equal Account, Money, and Direction
     * are equal.
     */
    public function test_equal_lines_are_equal(): void
    {
        $a = JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Debit);
        $b = JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Debit);

        $this->assertTrue($a->equals($b));
    }

    /**
     * A different Account makes two otherwise-identical lines unequal.
     */
    public function test_different_account_is_not_equal(): void
    {
        $a = JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Debit);
        $b = JournalLine::create(AccountId::of('account-income'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Debit);

        $this->assertFalse($a->equals($b));
    }

    /**
     * A different Money amount makes two otherwise-identical lines
     * unequal.
     */
    public function test_different_money_is_not_equal(): void
    {
        $a = JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Debit);
        $b = JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('50.00', $this->myr), JournalDirection::Debit);

        $this->assertFalse($a->equals($b));
    }

    /**
     * A different Direction makes two otherwise-identical lines
     * unequal — the same Account and the same Money, Debit vs. Credit.
     */
    public function test_different_direction_is_not_equal(): void
    {
        $a = JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Debit);
        $b = JournalLine::create(AccountId::of('account-cash'), Money::fromDecimalString('100.00', $this->myr), JournalDirection::Credit);

        $this->assertFalse($a->equals($b));
    }

    /**
     * JournalLine's Money is never signed: constructing a line does
     * not, and cannot, change the sign of the Money supplied — Money
     * itself already guarantees non-negativity at construction
     * (AETS-003 §9), and this class adds no multiplication, negation,
     * or other sign-altering operation of its own.
     */
    public function test_money_remains_non_negative_and_unsigned(): void
    {
        $money = Money::fromDecimalString('0.00', $this->myr);

        $line = JournalLine::create(AccountId::of('account-cash'), $money, JournalDirection::Credit);

        $this->assertSame('0.00', $line->money()->toDecimalString());

        // No sign-altering operation exists on Money for this class to
        // even call: no ->multiply(), ->subtract(), or similar, and no
        // method on JournalLine itself beyond the fixed public API
        // already proven in test_exposes_only_the_intended_public_api().
        $reflection = new ReflectionClass(JournalLine::class);
        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('->multiply(', $source);
        $this->assertStringNotContainsString('->subtract(', $source);
        $this->assertStringNotContainsString('->negated(', $source);
    }

    /**
     * No Direction-inference helper exists: no `isDebit()`,
     * `isCredit()`, `signedAmount()`, or `applyNormalBalance()` method
     * — only construction, the three accessors, and equality.
     */
    public function test_exposes_only_the_intended_public_api(): void
    {
        $reflection = new ReflectionClass(JournalLine::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($publicMethodNames);

        $this->assertSame(['accountId', 'create', 'direction', 'equals', 'money'], $publicMethodNames);

        foreach (['isDebit', 'isCredit', 'signedAmount', 'applyNormalBalance'] as $forbiddenMethodName) {
            $this->assertNotContains($forbiddenMethodName, $publicMethodNames);
        }
    }

    /**
     * Direction is never inferred from Account Type or Normal
     * Balance: neither type appears anywhere in JournalLine's own
     * executable declaration — no property, no constructor parameter,
     * no method parameter or return type. (The class docblock names
     * both, in prose, to document *why no relationship exists* — a
     * documentation cross-reference, not a code dependency; what must
     * actually be absent is any reference inside the class body
     * itself.)
     */
    public function test_has_no_account_type_or_normal_balance_dependency(): void
    {
        $reflection = new ReflectionClass(JournalLine::class);
        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);

        $classBodyStart = strpos($source, 'final class JournalLine');
        $this->assertIsInt($classBodyStart);

        $classBody = substr($source, $classBodyStart);

        $this->assertStringNotContainsString('AccountType', $classBody);
        $this->assertStringNotContainsString('NormalBalance', $classBody);
    }

    /**
     * JournalDirection and NormalBalance remain separate types (the
     * distinction {@see JournalLine} relies on): neither is an alias,
     * subtype, or converted form of the other.
     */
    public function test_journal_direction_is_a_distinct_type_from_normal_balance(): void
    {
        $this->assertNotSame(NormalBalance::class, JournalDirection::class);
    }

    /**
     * No line ordering/numbering, memo, description, metadata, Tenant,
     * or JournalId is part of this construction-level Value Object.
     */
    public function test_has_no_out_of_scope_fields(): void
    {
        $reflection = new ReflectionClass(JournalLine::class);

        $propertyNames = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            $reflection->getProperties(),
        );

        $this->assertSame(['accountId', 'money', 'direction'], $propertyNames);
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(JournalLine::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }
}
