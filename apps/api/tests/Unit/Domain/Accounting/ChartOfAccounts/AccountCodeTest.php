<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\ChartOfAccounts;

use App\Domain\Accounting\ChartOfAccounts\AccountCode;
use App\Domain\Accounting\ChartOfAccounts\Exception\InvalidAccountCodeException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the ATS-005 Account Code Tests whose expected behavior is
 * already normatively defined for a bare Value Object — AETS-005 §8
 * explicitly defers the final Account Code grammar, so this suite does
 * not test any character-set, digit-count, or numeric-vs-alphabetic
 * assumption. Tenant-scoped uniqueness (`COA-T024`), cross-tenant reuse
 * (`COA-T025`), System Account code immutability (`COA-T027`), and
 * User-Created Account code mutability (`COA-T028`) are all Account/
 * Tenant-level concerns not testable until Account exists — see
 * M2-T3's report.
 */
final class AccountCodeTest extends TestCase
{
    /**
     * A representative non-empty value constructs successfully —
     * partial coverage of COA-T023 ("an Account cannot be constructed
     * without an Account Code"): the closest available proxy before
     * Account exists is that AccountCode itself requires a non-empty
     * value at construction.
     */
    public function test_valid_code_constructs_successfully(): void
    {
        $code = AccountCode::of('CASH-001');

        $this->assertInstanceOf(AccountCode::class, $code);
    }

    /**
     * AETS-005 §8 explicitly does not assume codes must be numeric —
     * a numeric-looking code is neither required nor forbidden.
     */
    public function test_numeric_looking_code_is_accepted(): void
    {
        $code = AccountCode::of('1000');

        $this->assertSame('1000', $code->toString());
    }

    /**
     * Exact string round-trip: the value returned by `toString()` is
     * exactly the value supplied to `of()`, unchanged.
     */
    public function test_exact_string_round_trip(): void
    {
        $code = AccountCode::of('GENERAL-EXPENSE');

        $this->assertSame('GENERAL-EXPENSE', $code->toString());
    }

    /**
     * Value equality: two AccountCode instances constructed from the
     * same value are equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = AccountCode::of('1000');
        $b = AccountCode::of('1000');

        $this->assertTrue($a->equals($b));
    }

    /**
     * Value equality: two AccountCode instances constructed from
     * different values are not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = AccountCode::of('1000');
        $b = AccountCode::of('1100');

        $this->assertFalse($a->equals($b));
    }

    /**
     * Empty input is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidAccountCodeException::class);

        AccountCode::of('');
    }

    /**
     * Whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidAccountCodeException::class);

        AccountCode::of('   ');
    }

    /**
     * Leading whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_leading_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidAccountCodeException::class);

        AccountCode::of(' 1000');
    }

    /**
     * Trailing whitespace is rejected outright — never silently
     * trimmed to a shorter, altered value.
     */
    public function test_trailing_whitespace_is_rejected_not_normalized(): void
    {
        $this->expectException(InvalidAccountCodeException::class);

        AccountCode::of('1000 ');
    }

    /**
     * A control character (here, a null byte) is rejected — not
     * human-auditable, regardless of the still-deferred final grammar.
     */
    public function test_control_character_is_rejected(): void
    {
        $this->expectException(InvalidAccountCodeException::class);

        AccountCode::of("1000\0");
    }

    /**
     * A newline embedded in an otherwise plausible code is rejected.
     */
    public function test_embedded_newline_is_rejected(): void
    {
        $this->expectException(InvalidAccountCodeException::class);

        AccountCode::of("1000\n");
    }

    /**
     * COA-T087: an adversarially long Account Code is rejected via a
     * bounded, deterministic check.
     */
    public function test_adversarially_long_input_is_rejected(): void
    {
        $this->expectException(InvalidAccountCodeException::class);

        AccountCode::of(str_repeat('1', 1000));
    }

    /**
     * AccountCode is immutable: every property is readonly and no
     * public mutator method exists.
     */
    public function test_account_code_is_immutable(): void
    {
        $reflection = new ReflectionClass(AccountCode::class);

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
     * COA-T026 / COA-020: AccountCode must not represent or expose a
     * database identifier — no public method returns a native PHP int
     * as the canonical representation of the value, mirroring Money's
     * own MinorUnits/MON-014 pattern for this same concern.
     */
    public function test_no_native_int_canonical_accessor(): void
    {
        $reflection = new ReflectionClass(AccountCode::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($returnType instanceof \ReflectionNamedType) {
                $this->assertNotSame(
                    'int',
                    $returnType->getName(),
                    sprintf('Public method "%s" must not return a native int.', $method->getName()),
                );
            }
        }
    }

    /**
     * AccountCode exposes no arithmetic, database, or other
     * business-behavior method of its own — only construction,
     * exact-string output, and equality.
     */
    public function test_exposes_only_construction_output_and_equality(): void
    {
        $reflection = new ReflectionClass(AccountCode::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);
    }

    /**
     * No native database ID semantics / no database awareness:
     * AccountCode does not extend, implement, or otherwise depend on
     * any Eloquent or database-related type.
     */
    public function test_has_no_database_awareness(): void
    {
        $reflection = new ReflectionClass(AccountCode::class);

        $this->assertFalse($reflection->getParentClass());
        $this->assertSame([], $reflection->getInterfaceNames());
    }

    /**
     * Framework independence: no Illuminate/Eloquent dependency
     * anywhere in the file, matching the Money domain's own established
     * scan pattern.
     */
    public function test_has_no_framework_dependency(): void
    {
        $reflection = new ReflectionClass(AccountCode::class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Illuminate\\', $source);
        $this->assertStringNotContainsString('Eloquent', $source);
    }
}
