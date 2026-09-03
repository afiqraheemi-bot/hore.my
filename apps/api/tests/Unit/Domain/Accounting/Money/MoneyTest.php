<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Money;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\CurrencyMismatchException;
use App\Domain\Accounting\Money\Exception\InvalidMoneyAmountException;
use App\Domain\Accounting\Money\Exception\MoneyArithmeticException;
use App\Domain\Accounting\Money\Exception\RoundingRequiredException;
use App\Domain\Accounting\Money\Exception\UnresolvedMoneySignPolicyException;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use Brick\Math\Exception\DivisionByZeroException;
use Brick\Math\Exception\RoundingNecessaryException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the Money-related ATS-003 test IDs relevant to this task:
 * MON-T022 (valid parsing / golden zero), MON-T023 (malformed),
 * MON-T024 (over-precision), MON-T025 (under-precision), MON-T026 /
 * MON-T027 (locale-formatted), MON-T029 (scientific notation),
 * MON-T032 / MON-T033 (whitespace), MON-T034 (float rejection at the
 * public boundary), MON-T035 (exact addition), MON-T036 / MON-T038 /
 * MON-T049 (currency mismatch), MON-T037 (bounded subtraction),
 * MON-T045 / MON-T046 (equality), MON-T048 (compare consistency),
 * MON-T006 (immutability), MON-T067 (no vendor-type leakage), MON-T094
 * (negative decimal input deferred), MON-T095 (negative subtraction
 * result deferred).
 *
 * The decimal/MinorUnits round-trip requirements are demonstrated here
 * as concrete example-based tests (MON-008 / MON-012), not as the
 * property-based tests MON-T079/MON-T080 specify — no property-testing
 * library is selected for hore.my yet (ATS-003 §23).
 *
 * Money arithmetic (add/subtract/compareTo) is implemented internally
 * via brick/money, wrapped entirely as a private detail — see Money's
 * own class docblock. This removes the native-PHP_INT_MAX arithmetic
 * ceiling a prior version of this class had; `MoneyOverflowException`
 * no longer exists, per M1-T3's correction.
 */
final class MoneyTest extends TestCase
{
    private Currency $myr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->myr = Currency::of('MYR');
    }

    /**
     * MON-T022 (golden zero): "0.00" parses successfully.
     */
    public function test_valid_zero_construction_succeeds(): void
    {
        $money = Money::fromDecimalString('0.00', $this->myr);

        $this->assertSame('0.00', $money->toDecimalString());
    }

    /**
     * Valid MYR construction from a typical amount.
     */
    public function test_valid_myr_construction_succeeds(): void
    {
        $money = Money::fromDecimalString('10.25', $this->myr);

        $this->assertInstanceOf(Money::class, $money);
        $this->assertTrue($this->myr->equals($money->currency()));
    }

    /**
     * MON-008 / MON-012 (concrete, not the property-based MON-T079):
     * decimal -> Money -> decimal round-trip is exact.
     */
    public function test_decimal_to_money_to_decimal_round_trip(): void
    {
        $money = Money::fromDecimalString('999999.99', $this->myr);

        $this->assertSame('999999.99', $money->toDecimalString());
    }

    /**
     * MON-008 (concrete, not the property-based MON-T080): MinorUnits
     * -> Money -> MinorUnits round-trip is exact.
     */
    public function test_minor_units_to_money_to_minor_units_round_trip(): void
    {
        $minorUnits = MinorUnits::of('99999999');

        $money = Money::fromMinorUnits($minorUnits, $this->myr);

        $this->assertTrue($minorUnits->equals($money->toMinorUnits()));
    }

    /**
     * MON-T035: add() produces the exact mathematical sum.
     */
    public function test_exact_addition(): void
    {
        $a = Money::fromDecimalString('10.25', $this->myr);
        $b = Money::fromDecimalString('5.00', $this->myr);

        $sum = $a->add($b);

        $this->assertSame('15.25', $sum->toDecimalString());
    }

    /**
     * MON-T037: subtract() produces the exact mathematical difference
     * for a non-negative result — the only case ATS-003 currently
     * scopes this test to.
     */
    public function test_subtraction_with_non_negative_result(): void
    {
        $a = Money::fromDecimalString('10.25', $this->myr);
        $b = Money::fromDecimalString('5.00', $this->myr);

        $difference = $a->subtract($b);

        $this->assertSame('5.25', $difference->toDecimalString());
    }

    /**
     * Addition is exact beyond native PHP_INT_MAX — brick/money removes
     * the artificial native-int arithmetic ceiling entirely.
     */
    public function test_addition_beyond_php_int_max_is_exact(): void
    {
        $huge = Money::fromMinorUnits(
            MinorUnits::of('99999999999999999999999999999999999999'),
            $this->myr,
        );
        $one = Money::fromMinorUnits(MinorUnits::of('1'), $this->myr);

        $sum = $huge->add($one);

        $this->assertSame(
            '100000000000000000000000000000000000000',
            $sum->toMinorUnits()->toString(),
        );
    }

    /**
     * Subtraction is exact beyond native PHP_INT_MAX for a non-negative
     * result.
     */
    public function test_subtraction_beyond_php_int_max_is_exact(): void
    {
        $huge = Money::fromMinorUnits(
            MinorUnits::of('100000000000000000000000000000000000000'),
            $this->myr,
        );
        $one = Money::fromMinorUnits(MinorUnits::of('1'), $this->myr);

        $difference = $huge->subtract($one);

        $this->assertSame(
            '99999999999999999999999999999999999999',
            $difference->toMinorUnits()->toString(),
        );
    }

    /**
     * No native-float conversion occurs anywhere in construction,
     * arithmetic, or output, even for a magnitude that would silently
     * become an inexact float if PHP's native operators were used.
     */
    public function test_no_native_float_conversion_at_extreme_magnitude(): void
    {
        $numeral = '99999999999999999999999999999999999999';

        $money = Money::fromMinorUnits(MinorUnits::of($numeral), $this->myr);

        // If this value had been silently coerced through a native
        // float anywhere, the exact digit string would not survive
        // the round-trip.
        $this->assertSame($numeral, $money->toMinorUnits()->toString());
        $this->assertIsString($money->toDecimalString());
    }

    /**
     * MON-T095: a subtraction whose result would be negative is
     * rejected with a distinct, clearly labelled exception, not
     * silently resolved.
     */
    public function test_subtraction_with_negative_result_is_blocked_by_sign_policy(): void
    {
        $a = Money::fromDecimalString('5.00', $this->myr);
        $b = Money::fromDecimalString('10.25', $this->myr);

        $this->expectException(UnresolvedMoneySignPolicyException::class);

        $a->subtract($b);
    }

    /**
     * MON-T094: a grammatically valid negative decimal string (per
     * AETS-003 §9's own grammar) is rejected with a distinct exception,
     * not treated as merely malformed.
     */
    public function test_negative_decimal_string_is_blocked_by_sign_policy(): void
    {
        $this->expectException(UnresolvedMoneySignPolicyException::class);

        Money::fromDecimalString('-10.25', $this->myr);
    }

    /**
     * MON-T036: add() between different-Currency Money fails.
     */
    public function test_add_rejects_currency_mismatch(): void
    {
        $myr = Money::fromDecimalString('10.00', $this->myr);
        $other = $this->currencyOtherThanMyr();

        $this->expectException(CurrencyMismatchException::class);

        $myr->add(Money::fromDecimalString('1.00', $other));
    }

    /**
     * MON-T038: subtract() between different-Currency Money fails.
     */
    public function test_subtract_rejects_currency_mismatch(): void
    {
        $myr = Money::fromDecimalString('10.00', $this->myr);
        $other = $this->currencyOtherThanMyr();

        $this->expectException(CurrencyMismatchException::class);

        $myr->subtract(Money::fromDecimalString('1.00', $other));
    }

    /**
     * MON-T049: compareTo() between different-Currency Money fails.
     */
    public function test_compare_rejects_currency_mismatch(): void
    {
        $myr = Money::fromDecimalString('10.00', $this->myr);
        $other = $this->currencyOtherThanMyr();

        $this->expectException(CurrencyMismatchException::class);

        $myr->compareTo(Money::fromDecimalString('1.00', $other));
    }

    /**
     * MON-T045 / MON-T046: equality is exact — equal for the same
     * amount and Currency, not equal for a different amount.
     */
    public function test_equality_is_exact(): void
    {
        $a = Money::fromDecimalString('10.25', $this->myr);
        $b = Money::fromDecimalString('10.25', $this->myr);
        $c = Money::fromDecimalString('10.26', $this->myr);

        $this->assertNotSame($a, $b);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    /**
     * MON-T048: compareTo() is consistent — reflexive, and correctly
     * orders a smaller, equal, and larger amount.
     */
    public function test_compare_consistency(): void
    {
        $small = Money::fromDecimalString('5.00', $this->myr);
        $mid = Money::fromDecimalString('10.00', $this->myr);
        $midAgain = Money::fromDecimalString('10.00', $this->myr);
        $large = Money::fromDecimalString('15.00', $this->myr);

        $this->assertSame(0, $mid->compareTo($midAgain));
        $this->assertLessThan(0, $small->compareTo($mid));
        $this->assertGreaterThan(0, $large->compareTo($mid));
    }

    /**
     * MON-T023: a malformed decimal string is rejected.
     */
    public function test_malformed_string_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('abc', $this->myr);
    }

    /**
     * MON-T024: over-precision is rejected, never silently rounded.
     */
    public function test_over_precision_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('10.250', $this->myr);
    }

    /**
     * MON-T025: under-precision (including no decimal point at all) is
     * rejected — canonical MYR input requires exactly scale 2.
     */
    public function test_under_precision_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('10.2', $this->myr);
    }

    public function test_missing_decimal_point_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('10', $this->myr);
    }

    /**
     * MON-T026: a locale-formatted (comma) decimal separator is
     * rejected.
     */
    public function test_locale_formatted_comma_separator_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('10,25', $this->myr);
    }

    /**
     * MON-T027: a thousands-separator amount is rejected.
     */
    public function test_thousands_separator_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('1,000.00', $this->myr);
    }

    /**
     * MON-T029: scientific notation is rejected.
     */
    public function test_scientific_notation_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('1e2', $this->myr);
    }

    /**
     * MON-T033: leading/trailing whitespace is rejected, not trimmed.
     */
    public function test_whitespace_padded_input_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString(' 10.25 ', $this->myr);
    }

    /**
     * MON-T032: whitespace-only input is rejected.
     */
    public function test_whitespace_only_input_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('   ', $this->myr);
    }

    /**
     * MON-T031 (adjacent): empty string is rejected.
     */
    public function test_empty_string_is_rejected(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::fromDecimalString('', $this->myr);
    }

    /**
     * MON-T034: a native float cannot even be passed to
     * fromDecimalString — the `string` parameter type, combined with
     * `declare(strict_types=1)`, makes PHP itself reject a float
     * argument with a TypeError before the method body ever runs. This
     * is a stronger guarantee than a runtime check.
     */
    public function test_float_is_rejected_at_the_public_boundary(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line argument.type (deliberately passing an invalid type to prove the boundary rejects it) */
        Money::fromDecimalString(10.25, $this->myr);
    }

    /**
     * MON-T006: no operation on a constructed Money mutates it.
     */
    public function test_money_is_immutable(): void
    {
        $original = Money::fromDecimalString('10.25', $this->myr);
        $addend = Money::fromDecimalString('5.00', $this->myr);

        $original->add($addend);

        $this->assertSame('10.25', $original->toDecimalString());
    }

    /**
     * MON-T067: no vendor (Brick or otherwise) namespace type appears
     * in any public Money method signature.
     */
    public function test_no_vendor_type_in_public_api(): void
    {
        $reflection = new ReflectionClass(Money::class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $this->assertNotInstanceOfVendorType($type, $method->getName());
            }

            $this->assertNotInstanceOfVendorType($method->getReturnType(), $method->getName());
        }
    }

    /**
     * Brick exceptions do not escape the wrapper. Money's own
     * currency-mismatch pre-check makes reaching Brick's exception path
     * through the public API unreachable in normal operation — this
     * test proves the translation safety net itself (`guardedBrickCall`)
     * correctly converts a genuine vendor exception into a hore.my-owned
     * one, rather than assuming it based on the pre-check alone.
     */
    /**
     * Brick exceptions do not escape the wrapper, and no trace of the
     * vendor exception is observable through any public means once
     * translated. Money's own currency-mismatch pre-check makes
     * reaching Brick's exception path through the public API
     * unreachable in normal operation — this test proves the
     * translation safety net itself (`guardedBrickCall`), narrowly
     * scoped to that one private method via reflection, rather than
     * asserting behaviour indirectly.
     */
    public function test_brick_exceptions_are_translated_and_do_not_escape(): void
    {
        $money = Money::fromDecimalString('10.25', $this->myr);

        $reflection = new ReflectionClass(Money::class);
        $method = $reflection->getMethod('guardedBrickCall');

        $brickCurrency = new \Brick\Money\Currency('MYR', null, 'MYR', 2);
        $vendorException = new \Brick\Money\Exception\CurrencyMismatchException(
            'vendor-level mismatch, forced for this test',
            $brickCurrency,
            $brickCurrency,
        );

        try {
            $method->invoke($money, 'test-operation', function () use ($vendorException): void {
                throw $vendorException;
            });
            $this->fail('Expected MoneyArithmeticException was not thrown.');
        } catch (MoneyArithmeticException $caught) {
            // The thrown type is hore.my-owned.
            $this->assertInstanceOf(MoneyArithmeticException::class, $caught);

            // getPrevious() must not expose the Brick exception object.
            $this->assertNull($caught->getPrevious());

            // The public message must not name the vendor namespace or
            // class, or otherwise reveal vendor-specific detail.
            $this->assertStringNotContainsString('Brick', $caught->getMessage());
            $this->assertStringNotContainsString('CurrencyMismatchException', $caught->getMessage());
            $this->assertStringNotContainsString(
                'vendor-level mismatch, forced for this test',
                $caught->getMessage(),
            );
        }
    }

    /**
     * M1-T4: a vendor division-by-zero failure is translated into
     * hore.my's own {@see \App\Domain\Accounting\Money\Exception\DivisionByZeroException},
     * not the generic {@see MoneyArithmeticException}. Not yet
     * reachable through the public API (`divide` is not implemented),
     * so the translation machinery itself is exercised directly and
     * narrowly, via the same `guardedBrickCall` reflection already
     * used above — no new reflection surface is introduced.
     */
    public function test_brick_division_by_zero_is_translated_to_the_specific_exception(): void
    {
        $money = Money::fromDecimalString('10.25', $this->myr);

        $reflection = new ReflectionClass(Money::class);
        $method = $reflection->getMethod('guardedBrickCall');

        $this->expectException(\App\Domain\Accounting\Money\Exception\DivisionByZeroException::class);

        $method->invoke($money, 'test-operation', function (): void {
            throw new DivisionByZeroException('vendor division by zero, forced for this test');
        });
    }

    /**
     * M1-T4: a vendor rounding-necessary failure is translated into
     * hore.my's own {@see RoundingRequiredException},
     * not the generic {@see MoneyArithmeticException}. Same scope note
     * as the division-by-zero test above.
     */
    public function test_brick_rounding_necessary_is_translated_to_the_specific_exception(): void
    {
        $money = Money::fromDecimalString('10.25', $this->myr);

        $reflection = new ReflectionClass(Money::class);
        $method = $reflection->getMethod('guardedBrickCall');

        $this->expectException(RoundingRequiredException::class);

        $method->invoke($money, 'test-operation', function (): void {
            throw new RoundingNecessaryException('vendor rounding necessary, forced for this test');
        });
    }

    /**
     * Repo-wide: the `Brick\` namespace is referenced only inside the
     * approved Money wrapper implementation (`Money.php`) — nowhere
     * else under `app/`. A plain file-content scan, not reflection.
     */
    public function test_brick_namespace_is_confined_to_the_approved_money_wrapper(): void
    {
        $appRoot = dirname(__DIR__, 5).'/app';
        $allowedFile = realpath($appRoot.'/Domain/Accounting/Money/Money.php');
        $this->assertIsString($allowedFile, 'Expected Money.php to exist at the known path.');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);

            if (str_contains($contents, 'Brick\\')) {
                $this->assertSame(
                    $allowedFile,
                    realpath($file->getPathname()),
                    sprintf('Unexpected Brick namespace reference in "%s".', $file->getPathname()),
                );
            }
        }
    }

    private function assertNotInstanceOfVendorType(?\ReflectionType $type, string $context): void
    {
        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $this->assertStringStartsNotWith(
            'Brick\\',
            $type->getName(),
            sprintf('Method "%s" must not expose a vendor type.', $context),
        );
    }

    /**
     * Constructs a Currency instance with an identifier other than MYR
     * purely to exercise cross-currency guarding. MVP's supported
     * registry contains only MYR, so this uses reflection to bypass
     * Currency's private constructor for this one test — mirroring the
     * same technique used in CurrencyTest.
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
