<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\NormalBalance;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Covers the ATS-005 tests relevant to {@see NormalBalance} alone
 * (part of the Normal Balance Tests section). The `AccountType` →
 * `NormalBalance` mapping itself is covered in
 * {@see AccountTypeTest},
 * since it is `AccountType::normalBalance()` that owns that mapping
 * (AETS-005 §11).
 */
final class NormalBalanceTest extends TestCase
{
    /**
     * Debit case exists.
     */
    public function test_debit_case_exists(): void
    {
        $this->assertInstanceOf(NormalBalance::class, NormalBalance::Debit);
    }

    /**
     * Credit case exists.
     */
    public function test_credit_case_exists(): void
    {
        $this->assertInstanceOf(NormalBalance::class, NormalBalance::Credit);
    }

    /**
     * Exactly two NormalBalance cases — no more, no fewer.
     */
    public function test_defines_exactly_two_cases(): void
    {
        $this->assertSame(
            [NormalBalance::Debit, NormalBalance::Credit],
            NormalBalance::cases(),
        );
    }

    /**
     * No persistence/backing value is introduced: the enum is
     * unbacked, so no arbitrary string/int value can be coerced into
     * one via `from()`/`tryFrom()` — those methods do not exist on an
     * unbacked enum at all.
     */
    public function test_unbacked_enum_admits_no_unsupported_value(): void
    {
        $reflection = new ReflectionEnum(NormalBalance::class);

        $this->assertFalse($reflection->isBacked());
        $this->assertFalse($reflection->hasMethod('from'));
        $this->assertFalse($reflection->hasMethod('tryFrom'));
    }

    /**
     * PHP enum cases are singletons: two references to the same
     * NormalBalance are always identical, never a copy.
     */
    public function test_case_identity_is_stable(): void
    {
        $a = NormalBalance::Debit;
        $b = NormalBalance::Debit;

        $this->assertSame($a, $b);
    }

    /**
     * Architectural constraints this task requires directly: no
     * property beyond every PHP enum case's implicit `name`, no method
     * at all, no trait, and no interface beyond PHP's own implicit
     * `UnitEnum`.
     */
    public function test_enum_has_no_properties_methods_traits_or_interfaces(): void
    {
        $reflection = new ReflectionEnum(NormalBalance::class);

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
            static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === NormalBalance::class
                && ! in_array($method->getName(), $builtInEnumMethods, true),
        );
        $this->assertSame([], $ownMethods);
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file, matching the Money domain's own established
     * scan pattern.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionEnum(NormalBalance::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }
}
