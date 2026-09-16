<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Quotations;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Quotations\Exception\CorruptQuotationRecordException;
use App\Domain\Quotations\Exception\EmptyQuotationCannotBeSentException;
use App\Domain\Quotations\Exception\InvalidQuotationStatusTransitionException;
use App\Domain\Quotations\Exception\InvalidQuotationValidUntilException;
use App\Domain\Quotations\Exception\QuotationNotEditableException;
use App\Domain\Quotations\Exception\TooManyQuotationLinesException;
use App\Domain\Quotations\Quotation;
use App\Domain\Quotations\QuotationId;
use App\Domain\Quotations\QuotationLine;
use App\Domain\Quotations\QuotationStatus;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

/**
 * Covers the {@see Quotation} aggregate's own state-machine invariants
 * (AETS-016 §4, §6) purely at the entity level, mirroring
 * `Tests\Unit\Domain\Invoicing\InvoiceTest` exactly wherever the two
 * concepts are structurally parallel. Persistence and the atomic
 * conversion-to-Invoice path are covered separately by
 * `Tests\Feature\Domain\Quotations\QuotationConversionServiceIntegrationTest`.
 */
final class QuotationTest extends TestCase
{
    private const CURRENCY = 'MYR';

    public function test_drafts_a_quotation_with_no_lines_and_zero_total(): void
    {
        $quotation = $this->draftQuotation([]);

        $this->assertSame(QuotationStatus::Draft, $quotation->status());
        $this->assertNull($quotation->quotationNumber());
        $this->assertNull($quotation->issueDate());
        $this->assertNull($quotation->convertedInvoiceId());
        $this->assertSame('0.00', $quotation->totalAmount()->toDecimalString());
        $this->assertSame([], $quotation->lines());
    }

    public function test_drafts_a_quotation_summing_line_amounts_into_the_total(): void
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Item A', 2, Money::fromDecimalString('50.00', Currency::of(self::CURRENCY))),
            QuotationLine::of('Item B', 1, Money::fromDecimalString('30.00', Currency::of(self::CURRENCY))),
        ]);

        $this->assertSame('130.00', $quotation->totalAmount()->toDecimalString());
    }

    public function test_rejects_too_many_lines(): void
    {
        $lines = array_map(
            fn (int $i): QuotationLine => QuotationLine::of("Item {$i}", 1, Money::fromDecimalString('1.00', Currency::of(self::CURRENCY))),
            range(1, 201),
        );

        $this->expectException(TooManyQuotationLinesException::class);

        $this->draftQuotation($lines);
    }

    public function test_update_replaces_lines_and_recomputes_total(): void
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Item A', 1, Money::fromDecimalString('10.00', Currency::of(self::CURRENCY))),
        ]);

        $updated = $quotation->update(
            $quotation->customerId(),
            $quotation->validUntil(),
            [QuotationLine::of('Item B', 2, Money::fromDecimalString('25.00', Currency::of(self::CURRENCY)))],
        );

        $this->assertSame('10.00', $quotation->totalAmount()->toDecimalString());
        $this->assertSame('50.00', $updated->totalAmount()->toDecimalString());
        $this->assertTrue($updated->id()->equals($quotation->id()));
    }

    public function test_update_rejects_a_sent_quotation(): void
    {
        $sent = $this->sentQuotation();

        $this->expectException(QuotationNotEditableException::class);

        $sent->update($sent->customerId(), $sent->validUntil(), []);
    }

    public function test_send_transitions_draft_to_sent(): void
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Item A', 1, Money::fromDecimalString('100.00', Currency::of(self::CURRENCY))),
        ]);

        $sent = $quotation->send('QUO-000001', new \DateTimeImmutable('2026-09-16'));

        $this->assertSame(QuotationStatus::Sent, $sent->status());
        $this->assertSame('QUO-000001', $sent->quotationNumber());
        $this->assertNotNull($sent->issueDate());
        $this->assertSame(QuotationStatus::Draft, $quotation->status(), 'the original instance is unchanged');
    }

    public function test_send_rejects_an_already_sent_quotation(): void
    {
        $sent = $this->sentQuotation();

        $this->expectException(InvalidQuotationStatusTransitionException::class);

        $sent->send('QUO-000002', new \DateTimeImmutable('2026-09-16'));
    }

    public function test_send_rejects_an_empty_quotation(): void
    {
        $quotation = $this->draftQuotation([]);

        $this->expectException(EmptyQuotationCannotBeSentException::class);

        $quotation->send('QUO-000001', new \DateTimeImmutable('2026-09-16'));
    }

    public function test_send_rejects_a_valid_until_before_the_issue_date(): void
    {
        $quotation = Quotation::draft(
            QuotationId::of('quotation-0001'),
            TenantId::of('tenant-0001'),
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-01-01'),
            [QuotationLine::of('Item A', 1, Money::fromDecimalString('100.00', Currency::of(self::CURRENCY)))],
            Currency::of(self::CURRENCY),
        );

        $this->expectException(InvalidQuotationValidUntilException::class);

        $quotation->send('QUO-000001', new \DateTimeImmutable('2026-09-16'));
    }

    public function test_accept_transitions_sent_to_accepted(): void
    {
        $accepted = $this->sentQuotation()->accept();

        $this->assertSame(QuotationStatus::Accepted, $accepted->status());
    }

    public function test_accept_rejects_a_draft_quotation(): void
    {
        $quotation = $this->draftQuotation([]);

        $this->expectException(InvalidQuotationStatusTransitionException::class);

        $quotation->accept();
    }

    public function test_reject_is_valid_from_sent(): void
    {
        $rejected = $this->sentQuotation()->reject();

        $this->assertSame(QuotationStatus::Rejected, $rejected->status());
    }

    public function test_reject_is_valid_from_accepted(): void
    {
        $rejected = $this->sentQuotation()->accept()->reject();

        $this->assertSame(QuotationStatus::Rejected, $rejected->status());
    }

    public function test_reject_rejects_a_draft_quotation(): void
    {
        $quotation = $this->draftQuotation([]);

        $this->expectException(InvalidQuotationStatusTransitionException::class);

        $quotation->reject();
    }

    public function test_convert_transitions_accepted_to_converted_and_records_the_invoice_id(): void
    {
        $accepted = $this->sentQuotation()->accept();
        $invoiceId = InvoiceId::of('invoice-0001');

        $converted = $accepted->convert($invoiceId);

        $this->assertSame(QuotationStatus::Converted, $converted->status());
        $this->assertNotNull($converted->convertedInvoiceId());
        $this->assertTrue($converted->convertedInvoiceId()->equals($invoiceId));
    }

    public function test_convert_rejects_a_sent_quotation(): void
    {
        $sent = $this->sentQuotation();

        $this->expectException(InvalidQuotationStatusTransitionException::class);

        $sent->convert(InvoiceId::of('invoice-0001'));
    }

    public function test_terminal_states_match_aets_016_section_4(): void
    {
        $this->assertFalse(QuotationStatus::Draft->isTerminal());
        $this->assertFalse(QuotationStatus::Sent->isTerminal());
        $this->assertFalse(QuotationStatus::Accepted->isTerminal());
        $this->assertTrue(QuotationStatus::Rejected->isTerminal());
        $this->assertTrue(QuotationStatus::Converted->isTerminal());
    }

    /**
     * Mirrors `InvoiceTest::test_reconstitute_rejects_a_line_with_an_inconsistent_line_amount()`
     * exactly — proven from this aggregate's own first version (QUO-005)
     * rather than added after the fact.
     */
    public function test_reconstitute_rejects_a_line_with_an_inconsistent_line_amount(): void
    {
        $currency = Currency::of(self::CURRENCY);
        $corruptLine = QuotationLine::reconstitute(
            'Item A',
            2,
            Money::fromDecimalString('50.00', $currency),
            // Should be 100.00 (2 × 50.00) — deliberately wrong, as if
            // corrupted at the persistence layer.
            Money::fromDecimalString('999.00', $currency),
        );

        $this->expectException(CorruptQuotationRecordException::class);

        Quotation::reconstitute(
            QuotationId::of('quotation-corrupt-line'),
            TenantId::of('tenant-0001'),
            CustomerId::of('customer-0001'),
            null,
            QuotationStatus::Draft,
            null,
            new \DateTimeImmutable('2026-12-31'),
            null,
            [$corruptLine],
            Money::fromDecimalString('999.00', $currency),
        );
    }

    public function test_reconstitute_rejects_a_mismatched_total_amount(): void
    {
        $currency = Currency::of(self::CURRENCY);
        $line = QuotationLine::of('Item A', 1, Money::fromDecimalString('100.00', $currency));

        $this->expectException(CorruptQuotationRecordException::class);

        Quotation::reconstitute(
            QuotationId::of('quotation-corrupt-total'),
            TenantId::of('tenant-0001'),
            CustomerId::of('customer-0001'),
            null,
            QuotationStatus::Draft,
            null,
            new \DateTimeImmutable('2026-12-31'),
            null,
            [$line],
            // The one line sums to 100.00 — deliberately wrong.
            Money::fromDecimalString('500.00', $currency),
        );
    }

    public function test_equals_compares_by_identifier(): void
    {
        $a = $this->draftQuotation([]);
        $b = Quotation::reconstitute(
            $a->id(),
            TenantId::of('tenant-9999'),
            CustomerId::of('customer-9999'),
            null,
            QuotationStatus::Draft,
            null,
            $a->validUntil(),
            null,
            [],
            $a->totalAmount(),
        );

        $this->assertTrue($a->equals($b));
    }

    /**
     * @param  list<QuotationLine>  $lines
     */
    private function draftQuotation(array $lines): Quotation
    {
        return Quotation::draft(
            QuotationId::of('quotation-0001'),
            TenantId::of('tenant-0001'),
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-12-31'),
            $lines,
            Currency::of(self::CURRENCY),
        );
    }

    private function sentQuotation(): Quotation
    {
        $quotation = $this->draftQuotation([
            QuotationLine::of('Item A', 1, Money::fromDecimalString('100.00', Currency::of(self::CURRENCY))),
        ]);

        return $quotation->send('QUO-000001', new \DateTimeImmutable('2026-09-16'));
    }
}
