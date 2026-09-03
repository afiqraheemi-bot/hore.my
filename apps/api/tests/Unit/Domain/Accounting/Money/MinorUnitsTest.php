<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Money;

use App\Domain\Accounting\Money\Exception\InvalidMinorUnitsException;
use App\Domain\Accounting\Money\MinorUnits;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the MinorUnits-related ATS-003 cases relevant to this task:
 * MON-T015 (valid construction), MON-T016 (malformed/decimal/scientific
 * notation/whitespace rejection), MON-T017 (exact string
 * representation), MON-T018 (no native-int accessor), MON-T019 (no
 * arithmetic/business methods), MON-T020 (arbitrary-magnitude
 * round-trip), MON-T021 (value equality), MON-T093 (immutability).
 */
final class MinorUnitsTest extends TestCase
{
    /**
     * MON-T015: zero is a valid numeral.
     */
    public function test_valid_zero_construction_succeeds(): void
    {
        $minorUnits = MinorUnits::of('0');

        $this->assertInstanceOf(MinorUnits::class, $minorUnits);
    }

    /**
     * MON-T015: a valid positive integer numeral succeeds.
     */
    public function test_valid_positive_integer_numeral_succeeds(): void
    {
        $minorUnits = MinorUnits::of('1025');

        $this->assertInstanceOf(MinorUnits::class, $minorUnits);
    }

    /**
     * MON-T017: the exact string representation reproduces the
     * constructing input exactly (round-trip).
     */
    public function test_exact_string_round_trip(): void
    {
        $minorUnits = MinorUnits::of('1025');

        $this->assertSame('1025', $minorUnits->toString());
    }

    /**
     * MON-T020: an integer numeral far larger than native PHP_INT_MAX
     * round-trips exactly, without precision loss — MinorUnits is not
     * bounded to native int range (only the separate persistence-layer
     * signed-64-bit bound is, and that is out of this task's scope).
     */
    public function test_arbitrary_magnitude_round_trip_beyond_native_int(): void
    {
        $huge = '99999999999999999999999999999999999999';

        $minorUnits = MinorUnits::of($huge);

        $this->assertSame($huge, $minorUnits->toString());
    }

    /**
     * MON-T021: two independently constructed MinorUnits representing
     * the same exact integer are value-equal.
     */
    public function test_same_value_is_equal(): void
    {
        $a = MinorUnits::of('1025');
        $b = MinorUnits::of('1025');

        $this->assertNotSame($a, $b);
        $this->assertTrue($a->equals($b));
    }

    /**
     * MON-T021: MinorUnits representing different exact integers are
     * not equal.
     */
    public function test_different_value_is_not_equal(): void
    {
        $a = MinorUnits::of('1025');
        $b = MinorUnits::of('1026');

        $this->assertFalse($a->equals($b));
    }

    /**
     * MON-T016: a malformed (non-numeral) string is rejected.
     */
    public function test_malformed_string_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        MinorUnits::of('abc');
    }

    /**
     * MON-T016: a non-canonical leading-zero numeral is rejected — not
     * silently normalized to its canonical form.
     */
    public function test_leading_zero_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        MinorUnits::of('01025');
    }

    /**
     * MON-T016: a decimal string is rejected — MinorUnits is an
     * integer numeral, never a decimal.
     */
    public function test_decimal_string_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        MinorUnits::of('10.25');
    }

    /**
     * MON-T016: scientific notation is rejected.
     */
    public function test_scientific_notation_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        MinorUnits::of('1e2');
    }

    /**
     * MON-T016: whitespace-padded input is rejected, not trimmed.
     */
    public function test_whitespace_padded_input_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        MinorUnits::of(' 1025 ');
    }

    /**
     * MON-T016: whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        MinorUnits::of('   ');
    }

    /**
     * MON-T016: empty string is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidMinorUnitsException::class);

        MinorUnits::of('');
    }

    /**
     * MON-T093: MinorUnits is immutable — every property is readonly
     * and no public mutator method exists.
     */
    public function test_minor_units_is_immutable(): void
    {
        $reflection = new ReflectionClass(MinorUnits::class);

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
     * MON-T018: no public method returns a native PHP int as the
     * canonical representation of the value.
     */
    public function test_no_native_int_canonical_accessor(): void
    {
        $reflection = new ReflectionClass(MinorUnits::class);

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
     * MON-T019: MinorUnits exposes no arithmetic or business-behavior
     * method of its own — only construction, exact-string output, and
     * equality.
     */
    public function test_exposes_no_arithmetic_or_business_methods(): void
    {
        $reflection = new ReflectionClass(MinorUnits::class);

        $publicMethodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        $this->assertSame(['of', 'toString', 'equals'], $publicMethodNames);
    }
}
