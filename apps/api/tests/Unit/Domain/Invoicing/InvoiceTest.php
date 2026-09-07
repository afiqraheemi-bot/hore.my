<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Invoicing;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Exception\EmptyInvoiceCannotBeIssuedException;
use App\Domain\Invoicing\Exception\InvalidInvoiceDueDateException;
use App\Domain\Invoicing\Exception\InvalidInvoiceStatusTransitionException;
use App\Domain\Invoicing\Exception\InvoiceNotEditableException;
use App\Domain\Invoicing\Exception\TooManyInvoiceLinesException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Shared\Tenancy\TenantId;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    private const CURRENCY = 'MYR';

    public function test_drafts_an_invoice_with_no_lines_and_zero_total(): void
    {
        $invoice = $this->draftInvoice([]);

        $this->assertSame(InvoiceStatus::Draft, $invoice->status());
        $this->assertNull($invoice->invoiceNumber());
        $this->assertNull($invoice->issueDate());
        $this->assertNull($invoice->journalId());
        $this->assertSame('0.00', $invoice->totalAmount()->toDecimalString());
        $this->assertSame([], $invoice->lines());
    }

    public function test_drafts_an_invoice_summing_line_amounts_into_the_total(): void
    {
        $invoice = $this->draftInvoice([
            InvoiceLine::of('Item A', 2, Money::fromDecimalString('50.00', Currency::of(self::CURRENCY))),
            InvoiceLine::of('Item B', 1, Money::fromDecimalString('30.00', Currency::of(self::CURRENCY))),
        ]);

        $this->assertSame('130.00', $invoice->totalAmount()->toDecimalString());
    }

    public function test_rejects_too_many_lines(): void
    {
        $lines = array_map(
            fn (int $i): InvoiceLine => InvoiceLine::of("Item {$i}", 1, Money::fromDecimalString('1.00', Currency::of(self::CURRENCY))),
            range(1, 201),
        );

        $this->expectException(TooManyInvoiceLinesException::class);

        $this->draftInvoice($lines);
    }

    public function test_update_replaces_lines_and_recomputes_total(): void
    {
        $invoice = $this->draftInvoice([
            InvoiceLine::of('Item A', 1, Money::fromDecimalString('10.00', Currency::of(self::CURRENCY))),
        ]);

        $updated = $invoice->update(
            $invoice->dueDate(),
            $invoice->receivableAccountId(),
            $invoice->revenueAccountId(),
            [InvoiceLine::of('Item B', 2, Money::fromDecimalString('25.00', Currency::of(self::CURRENCY)))],
        );

        $this->assertSame('10.00', $invoice->totalAmount()->toDecimalString());
        $this->assertSame('50.00', $updated->totalAmount()->toDecimalString());
        $this->assertTrue($updated->id()->equals($invoice->id()));
    }

    public function test_update_rejects_an_already_issued_invoice(): void
    {
        $issued = $this->issuedInvoice();

        $this->expectException(InvoiceNotEditableException::class);

        $issued->update($issued->dueDate(), $issued->receivableAccountId(), $issued->revenueAccountId(), []);
    }

    public function test_issue_transitions_draft_to_issued(): void
    {
        $invoice = $this->draftInvoice([
            InvoiceLine::of('Item A', 1, Money::fromDecimalString('100.00', Currency::of(self::CURRENCY))),
        ]);

        $issued = $invoice->issue('INV-000001', new \DateTimeImmutable('2026-09-08'), JournalId::of('journal-0001'));

        $this->assertSame(InvoiceStatus::Issued, $issued->status());
        $this->assertSame('INV-000001', $issued->invoiceNumber());
        $this->assertNotNull($issued->issueDate());
        $this->assertNotNull($issued->journalId());
        $this->assertSame(InvoiceStatus::Draft, $invoice->status(), 'the original instance is unchanged');
    }

    public function test_issue_rejects_an_already_issued_invoice(): void
    {
        $issued = $this->issuedInvoice();

        $this->expectException(InvalidInvoiceStatusTransitionException::class);

        $issued->issue('INV-000002', new \DateTimeImmutable('2026-09-08'), JournalId::of('journal-0002'));
    }

    public function test_issue_rejects_an_empty_invoice(): void
    {
        $invoice = $this->draftInvoice([]);

        $this->expectException(EmptyInvoiceCannotBeIssuedException::class);

        $invoice->issue('INV-000001', new \DateTimeImmutable('2026-09-08'), JournalId::of('journal-0001'));
    }

    public function test_issue_rejects_a_due_date_before_the_issue_date(): void
    {
        $invoice = Invoice::draft(
            InvoiceId::of('invoice-0001'),
            TenantId::of('tenant-0001'),
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-01-01'),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            [InvoiceLine::of('Item A', 1, Money::fromDecimalString('100.00', Currency::of(self::CURRENCY)))],
            Currency::of(self::CURRENCY),
        );

        $this->expectException(InvalidInvoiceDueDateException::class);

        $invoice->issue('INV-000001', new \DateTimeImmutable('2026-09-08'), JournalId::of('journal-0001'));
    }

    public function test_equals_compares_by_identifier(): void
    {
        $a = $this->draftInvoice([]);
        $b = Invoice::reconstitute(
            $a->id(),
            TenantId::of('tenant-9999'),
            CustomerId::of('customer-9999'),
            null,
            InvoiceStatus::Draft,
            null,
            $a->dueDate(),
            $a->receivableAccountId(),
            $a->revenueAccountId(),
            null,
            [],
            $a->totalAmount(),
        );

        $this->assertTrue($a->equals($b));
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private function draftInvoice(array $lines): Invoice
    {
        return Invoice::draft(
            InvoiceId::of('invoice-0001'),
            TenantId::of('tenant-0001'),
            CustomerId::of('customer-0001'),
            new \DateTimeImmutable('2026-12-31'),
            AccountId::of('account-receivable'),
            AccountId::of('account-revenue'),
            $lines,
            Currency::of(self::CURRENCY),
        );
    }

    private function issuedInvoice(): Invoice
    {
        $invoice = $this->draftInvoice([
            InvoiceLine::of('Item A', 1, Money::fromDecimalString('100.00', Currency::of(self::CURRENCY))),
        ]);

        return $invoice->issue('INV-000001', new \DateTimeImmutable('2026-09-08'), JournalId::of('journal-0001'));
    }
}
