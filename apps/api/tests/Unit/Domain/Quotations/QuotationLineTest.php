<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Quotations;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Quotations\Exception\InvalidQuotationLineException;
use App\Domain\Quotations\QuotationLine;
use PHPUnit\Framework\TestCase;

final class QuotationLineTest extends TestCase
{
    public function test_computes_line_amount_as_quantity_times_unit_price(): void
    {
        $line = QuotationLine::of('Consulting hours', 3, Money::fromDecimalString('150.00', Currency::of('MYR')));

        $this->assertSame('3', (string) $line->quantity());
        $this->assertSame('150.00', $line->unitPrice()->toDecimalString());
        $this->assertSame('450.00', $line->lineAmount()->toDecimalString());
    }

    public function test_rejects_an_empty_description(): void
    {
        $this->expectException(InvalidQuotationLineException::class);

        QuotationLine::of('', 1, Money::fromDecimalString('10.00', Currency::of('MYR')));
    }

    public function test_rejects_a_description_exceeding_the_max_length(): void
    {
        $this->expectException(InvalidQuotationLineException::class);

        QuotationLine::of(str_repeat('a', 501), 1, Money::fromDecimalString('10.00', Currency::of('MYR')));
    }

    public function test_rejects_a_zero_quantity(): void
    {
        $this->expectException(InvalidQuotationLineException::class);

        QuotationLine::of('Item', 0, Money::fromDecimalString('10.00', Currency::of('MYR')));
    }

    public function test_rejects_a_negative_quantity(): void
    {
        $this->expectException(InvalidQuotationLineException::class);

        QuotationLine::of('Item', -1, Money::fromDecimalString('10.00', Currency::of('MYR')));
    }

    public function test_reconstitute_performs_no_validation(): void
    {
        $currency = Currency::of('MYR');
        $line = QuotationLine::reconstitute(
            '',
            0,
            Money::fromDecimalString('10.00', $currency),
            Money::fromDecimalString('0.00', $currency),
        );

        $this->assertSame(0, $line->quantity());
    }

    public function test_has_consistent_line_amount_detects_a_mismatch(): void
    {
        $currency = Currency::of('MYR');
        $consistent = QuotationLine::of('Item', 2, Money::fromDecimalString('50.00', $currency));
        $corrupt = QuotationLine::reconstitute('Item', 2, Money::fromDecimalString('50.00', $currency), Money::fromDecimalString('999.00', $currency));

        $this->assertTrue($consistent->hasConsistentLineAmount());
        $this->assertFalse($corrupt->hasConsistentLineAmount());
    }
}
