<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Journal;

use App\Domain\Accounting\ChartOfAccounts\AccountType;
use App\Domain\Accounting\ChartOfAccounts\NormalBalance;
use App\Domain\Accounting\Journal\JournalDirection;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Covers the ATS-004 tests relevant to {@see JournalDirection} in
 * isolation (M3-T1) — the Debit/Credit classification primitive
 * itself, not yet a Journal Line, which does not exist in this
 * codebase yet. `JRN-T005`/`JRN-T006` ("a Journal Line with
 * Debit/Credit direction is valid") are exercised here only at the
 * level this type can prove alone: that `Debit` and `Credit` each
 * exist as a valid, constructible case. `JRN-T007`/`JRN-T008` ("a
 * Journal Line cannot represent both directions simultaneously, nor
 * neither") are properties of a future Journal Line's own
 * construction, not of this enum in isolation — what this suite does
 * prove, as their necessary foundation, is that the type itself
 * admits exactly two cases and nothing else, so no third state is
 * even representable.
 */
final class JournalDirectionTest extends TestCase
{
    /**
     * JRN-T005 (enum-level foundation): Debit case exists.
     */
    public function test_debit_case_exists(): void
    {
        $this->assertInstanceOf(JournalDirection::class, JournalDirection::Debit);
    }

    /**
     * JRN-T006 (enum-level foundation): Credit case exists.
     */
    public function test_credit_case_exists(): void
    {
        $this->assertInstanceOf(JournalDirection::class, JournalDirection::Credit);
    }

    /**
     * Exactly two JournalDirection cases — no more, no fewer. The
     * structural precondition JRN-T007/JRN-T008 will rely on once a
     * Journal Line exists: with only these two cases, a required
     * `JournalDirection` property can never represent "both" or
     * "neither."
     */
    public function test_defines_exactly_two_cases(): void
    {
        $this->assertSame(
            [JournalDirection::Debit, JournalDirection::Credit],
            JournalDirection::cases(),
        );
    }

    /**
     * No persistence/backing value is introduced: the enum is
     * unbacked, so no arbitrary string/int value can be coerced into
     * one via `from()`/`tryFrom()` — those methods do not exist on an
     * unbacked enum at all (AETS-004 does not lock a persisted
     * representation).
     */
    public function test_unbacked_enum_admits_no_unsupported_value(): void
    {
        $reflection = new ReflectionEnum(JournalDirection::class);

        $this->assertFalse($reflection->isBacked());
        $this->assertFalse($reflection->hasMethod('from'));
        $this->assertFalse($reflection->hasMethod('tryFrom'));
    }

    /**
     * PHP enum cases are singletons: two references to the same
     * JournalDirection are always identical, never a copy.
     */
    public function test_case_identity_is_stable(): void
    {
        $a = JournalDirection::Debit;
        $b = JournalDirection::Debit;

        $this->assertSame($a, $b);
    }

    /**
     * No property beyond every PHP enum case's implicit `name`, no
     * method at all — in particular, no helper method not directly
     * required by AETS-004 — no trait, and no interface beyond PHP's
     * own implicit `UnitEnum`.
     */
    public function test_enum_has_no_properties_methods_traits_or_interfaces(): void
    {
        $reflection = new ReflectionEnum(JournalDirection::class);

        $propertyNames = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            $reflection->getProperties(),
        );
        $this->assertSame(['name'], $propertyNames);

        $this->assertSame([], $reflection->getTraitNames());
        $this->assertSame(['UnitEnum'], $reflection->getInterfaceNames());

        $builtInEnumMethods = ['cases', 'from', 'tryFrom'];
        $ownMethods = array_filter(
            $reflection->getMethods(),
            static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === JournalDirection::class
                && ! in_array($method->getName(), $builtInEnumMethods, true),
        );
        $this->assertSame([], $ownMethods);
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionEnum(JournalDirection::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }

    /**
     * Direction MUST NOT be inferred from, mapped to, or convertible
     * from/to Account Type or Normal Balance (AETS-004 §8). The class
     * docblock references both by name only to document *why no
     * relationship exists* (Pint's house style resolves a docblock
     * `{@see}` tag to an import, which is why both appear as `use`
     * imports above — a documentation cross-reference, not a code
     * dependency). What must actually be absent is any reference
     * inside the enum's own executable declaration: no case, no
     * constant, no property, no method — proof that nothing there
     * could embed a mapping or conversion.
     */
    public function test_has_no_dependency_on_account_type_or_normal_balance(): void
    {
        $reflection = new ReflectionEnum(JournalDirection::class);
        $source = file_get_contents((string) $reflection->getFileName());
        $this->assertIsString($source);

        $enumBodyStart = strpos($source, 'enum JournalDirection');
        $this->assertIsInt($enumBodyStart);

        $enumBody = substr($source, $enumBodyStart);

        $this->assertStringNotContainsString('AccountType', $enumBody);
        $this->assertStringNotContainsString('NormalBalance', $enumBody);
        $this->assertSame(
            "enum JournalDirection\n{\n    case Debit;\n    case Credit;\n}",
            trim($enumBody),
        );
    }

    /**
     * JournalDirection and NormalBalance are separate types: neither
     * is an alias, subtype, or converted form of the other, even
     * though PHP enums with identical case names could otherwise be
     * confused for one another.
     */
    public function test_is_a_distinct_type_from_normal_balance(): void
    {
        $this->assertNotSame(NormalBalance::class, JournalDirection::class);
        $this->assertFalse(JournalDirection::Debit instanceof NormalBalance);
        $this->assertFalse(NormalBalance::Debit instanceof JournalDirection);
    }

    /**
     * No Money sign semantics are embedded in this type: it has no
     * dependency on the Money class at all (no `use` import, no
     * reference to it in code — the docblock's own prose explaining
     * the magnitude-with-direction distinction is not a code
     * dependency), and no property or method beyond the implicit
     * `name` (already proven above) through which a numeric value,
     * multiplier, or sign could be embedded.
     */
    public function test_carries_no_money_sign_semantics(): void
    {
        $reflection = new ReflectionEnum(JournalDirection::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('use App\Domain\Accounting\Money', $source);
        $this->assertStringNotContainsString('Money::', $source);
        $this->assertStringNotContainsString('Money $', $source);
    }

    /**
     * Not reused as, and not an alias of, {@see AccountType} either —
     * the two case names it shares are with NormalBalance, not
     * AccountType, but this closes the loop on "no reuse of an
     * unrelated Chart of Accounts enum" completely.
     */
    public function test_is_not_an_account_type(): void
    {
        $this->assertNotSame(AccountType::class, JournalDirection::class);
    }
}
