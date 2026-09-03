<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Accounting\Money;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Exception\InvalidCurrencyException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Covers the Currency-related ATS-003 cases relevant to this task:
 * MON-T009 (valid construction, scale, identifier), MON-T010 and MON-T092
 * (invalid/unsupported/case-variant rejection), MON-T011 (scale not
 * independently settable), MON-T012 (value equality), MON-T013
 * (immutability).
 */
final class CurrencyTest extends TestCase
{
    /**
     * MON-T009: valid MYR construction succeeds.
     */
    public function test_valid_myr_construction_succeeds(): void
    {
        $currency = Currency::of('MYR');

        $this->assertInstanceOf(Currency::class, $currency);
    }

    /**
     * MON-T009: MYR's canonical minor-unit scale is 2.
     */
    public function test_myr_scale_is_two(): void
    {
        $currency = Currency::of('MYR');

        $this->assertSame(2, $currency->scale());
    }

    /**
     * MON-T009: the canonical identifier exposed is exactly "MYR".
     */
    public function test_myr_canonical_identifier_is_myr(): void
    {
        $currency = Currency::of('MYR');

        $this->assertSame('MYR', $currency->identifier());
    }

    /**
     * MON-T012: two independently constructed Currency instances with the
     * same identifier are value-equal, not merely reference-equal.
     */
    public function test_two_currencies_with_same_identifier_are_value_equal(): void
    {
        $a = Currency::of('MYR');
        $b = Currency::of('MYR');

        $this->assertNotSame($a, $b);
        $this->assertTrue($a->equals($b));
    }

    /**
     * MON-T012: Currency instances with different identifiers are not
     * equal. MVP's supported-currency registry contains only MYR, so a
     * second instance with a different identifier is constructed via
     * reflection (bypassing the private constructor) purely to exercise
     * equals()'s comparison logic in isolation from which currencies are
     * currently supported.
     */
    public function test_currencies_with_different_identifiers_are_not_equal(): void
    {
        $myr = Currency::of('MYR');

        $reflection = new ReflectionClass(Currency::class);
        $other = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('identifier')->setValue($other, 'XXX');
        $reflection->getProperty('scale')->setValue($other, 2);

        $this->assertFalse($myr->equals($other));
    }

    /**
     * MON-T010 / MON-T092: a lowercase identifier is rejected, not
     * silently normalized.
     */
    public function test_lowercase_identifier_is_rejected(): void
    {
        $this->expectException(InvalidCurrencyException::class);

        Currency::of('myr');
    }

    /**
     * MON-T010 / MON-T092: a mixed-case identifier is rejected, not
     * silently normalized.
     */
    public function test_mixed_case_identifier_is_rejected(): void
    {
        $this->expectException(InvalidCurrencyException::class);

        Currency::of('Myr');
    }

    /**
     * MON-T010: a syntactically well-formed but unsupported identifier
     * is rejected.
     */
    public function test_unsupported_currency_is_rejected(): void
    {
        $this->expectException(InvalidCurrencyException::class);

        Currency::of('USD');
    }

    /**
     * MON-T011: no public construction path allows a scale to be
     * supplied independently of the identifier — the constructor is not
     * public, and the only public factory takes exactly one argument.
     */
    public function test_scale_cannot_be_supplied_independently_of_identifier(): void
    {
        $reflection = new ReflectionClass(Currency::class);

        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertFalse($constructor->isPublic());

        $factory = $reflection->getMethod('of');
        $this->assertTrue($factory->isPublic());
        $this->assertTrue($factory->isStatic());
        $this->assertCount(1, $factory->getParameters());
    }

    /**
     * MON-T013: Currency is immutable — every property is readonly and
     * no public mutator method exists.
     */
    public function test_currency_is_immutable(): void
    {
        $reflection = new ReflectionClass(Currency::class);

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
}
