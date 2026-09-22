<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\MyInvois;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\Customer;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\MyInvois\Exception\MissingMyInvoisDataException;
use App\Domain\MyInvois\MyInvoisLineDetail;
use App\Domain\MyInvois\MyInvoisTaxType;
use App\Domain\MyInvois\UblInvoiceDocumentBuilder;
use App\Domain\Shared\Tenancy\TenantId;
use App\Models\BusinessProfile;
use Tests\TestCase;

/**
 * Unit proof of `UblInvoiceDocumentBuilder` (AETS-013 v0.1.0 §7,
 * ATS-013 §4.2) — pure, no I/O, no database write. Every fixture is
 * constructed directly in memory; `BusinessProfile` (an Eloquent
 * model) is instantiated but never persisted, exactly like every
 * other Domain fixture in this file.
 */
final class UblInvoiceDocumentBuilderTest extends TestCase
{
    private const CURRENCY = 'MYR';

    // MYI-T007 (MYI-003)
    public function test_rejects_a_draft_invoice(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $invoice = $this->draftInvoice([$this->itemALine()]);

        $this->expectException(MissingMyInvoisDataException::class);

        $builder->build($invoice, $invoice->lines(), [0 => $this->itemADetail()], $this->customer(), $this->businessProfile());
    }

    // MYI-T008 (MYI-004)
    public function test_rejects_a_business_profile_missing_its_msic_code(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $invoice = $this->issuedInvoice([$this->itemALine()]);
        $profile = $this->businessProfile(['msic_code' => null]);

        $this->expectException(MissingMyInvoisDataException::class);
        $this->expectExceptionMessageMatches('/msic_code/');

        $builder->build($invoice, $invoice->lines(), [0 => $this->itemADetail()], $this->customer(), $profile);
    }

    // MYI-T008 (MYI-004) — supplier TIN
    public function test_rejects_a_business_profile_missing_its_tin(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $invoice = $this->issuedInvoice([$this->itemALine()]);
        $profile = $this->businessProfile(['tin' => null]);

        $this->expectException(MissingMyInvoisDataException::class);
        $this->expectExceptionMessageMatches('/tin/');

        $builder->build($invoice, $invoice->lines(), [0 => $this->itemADetail()], $this->customer(), $profile);
    }

    // MYI-T008 (MYI-004) — buyer TIN
    public function test_rejects_a_customer_missing_its_tin(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $invoice = $this->issuedInvoice([$this->itemALine()]);

        $this->expectException(MissingMyInvoisDataException::class);
        $this->expectExceptionMessageMatches('/tin/');

        $builder->build($invoice, $invoice->lines(), [0 => $this->itemADetail()], $this->customer(['tin' => null]), $this->businessProfile());
    }

    // MYI-T009 (MYI-004)
    public function test_rejects_a_line_missing_its_myinvois_detail(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $invoice = $this->issuedInvoice([$this->itemALine()]);

        $this->expectException(MissingMyInvoisDataException::class);
        $this->expectExceptionMessageMatches('/line 1/');

        $builder->build($invoice, $invoice->lines(), [], $this->customer(), $this->businessProfile());
    }

    // MYI-T010
    public function test_supplier_block_matches_the_business_profile_exactly(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $invoice = $this->issuedInvoice([$this->itemALine()]);

        $document = $builder->build($invoice, $invoice->lines(), [0 => $this->itemADetail()], $this->customer(), $this->businessProfile());

        $this->assertSame('Kedai Runcit Aina', $document['supplier']['name']);
        $this->assertSame('IG12345678090', $document['supplier']['tin']);
        $this->assertSame('SSM-0012345', $document['supplier']['registrationNumber']);
        $this->assertSame('SST-0099887766', $document['supplier']['sstRegistrationNumber']);
        $this->assertSame('47111', $document['supplier']['msicCode']);
        $this->assertSame('No. 12, Jalan Sutera', $document['supplier']['address']['addressLine1']);
        $this->assertSame('Petaling Jaya', $document['supplier']['address']['city']);
        $this->assertSame('Selangor', $document['supplier']['address']['state']);
        $this->assertSame('46000', $document['supplier']['address']['postcode']);
        $this->assertSame('MYS', $document['supplier']['address']['countryCode']);
    }

    // MYI-T011
    public function test_buyer_block_matches_the_customer_exactly(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $invoice = $this->issuedInvoice([$this->itemALine()]);

        $document = $builder->build($invoice, $invoice->lines(), [0 => $this->itemADetail()], $this->customer(), $this->businessProfile());

        $this->assertSame('Aina Trading', $document['buyer']['name']);
        $this->assertSame('C1234567890', $document['buyer']['tin']);
        $this->assertSame('No. 5, Jalan Mawar, Shah Alam', $document['buyer']['address']);
        $this->assertSame('+60123456789', $document['buyer']['contactNumber']);
    }

    // MYI-T012, MYI-T014
    public function test_one_line_entry_per_invoice_line_carrying_its_own_myinvois_fields(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $lines = [$this->itemALine(), $this->itemBLine()];
        $invoice = $this->issuedInvoice($lines);
        $details = [0 => $this->itemADetail(), 1 => $this->itemBDetail()];

        $document = $builder->build($invoice, $invoice->lines(), $details, $this->customer(), $this->businessProfile());

        $this->assertCount(2, $document['invoiceLineItems']);

        $lineA = $document['invoiceLineItems'][0];
        $this->assertSame('Consulting services', $lineA['item']['description']);
        $this->assertSame('9309000000', $lineA['item']['classificationCode']);
        $this->assertSame(2, $lineA['quantity']);
        $this->assertSame('DAY', $lineA['unitOfMeasure']);
        $this->assertSame('500.00', $lineA['unitPrice']);
        $this->assertSame('1000.00', $lineA['subtotal']);
        $this->assertSame('02', $lineA['taxType']);
        $this->assertSame('6.00', $lineA['taxRate']);
        $this->assertSame('60.00', $lineA['taxAmount']);

        $lineB = $document['invoiceLineItems'][1];
        $this->assertSame('Laptop stand', $lineB['item']['description']);
        $this->assertSame('06', $lineB['taxType']);
        $this->assertSame('0.00', $lineB['taxAmount']);

        $this->assertSame('01', $document['eInvoiceTypeCode']);
        $this->assertSame('1.0', $document['eInvoiceVersion']);
    }

    // MYI-T013
    public function test_financial_totals_reconcile_exactly_to_the_invoice(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $lines = [$this->itemALine(), $this->itemBLine()];
        $invoice = $this->issuedInvoice($lines);
        $details = [0 => $this->itemADetail(), 1 => $this->itemBDetail()];

        $document = $builder->build($invoice, $invoice->lines(), $details, $this->customer(), $this->businessProfile());

        // itemA: 1000.00 excl. tax, 60.00 tax; itemB: 150.00 excl. tax, 0.00 tax.
        $this->assertSame('1150.00', $document['legalMonetaryTotal']['totalExcludingTax']);
        $this->assertSame('60.00', $document['legalMonetaryTotal']['totalTaxAmount']);
        $this->assertSame('1210.00', $document['legalMonetaryTotal']['totalIncludingTax']);
        $this->assertSame('1210.00', $document['legalMonetaryTotal']['totalPayableAmount']);
        $this->assertSame($invoice->totalAmount()->toDecimalString(), $document['legalMonetaryTotal']['totalExcludingTax']);
    }

    // MYI-T015 — golden multi-line fixture, every LHDN-mandatory field present.
    public function test_golden_multi_line_invoice_produces_every_mandatory_field(): void
    {
        $builder = new UblInvoiceDocumentBuilder;
        $lines = [$this->itemALine(), $this->itemBLine()];
        $invoice = $this->issuedInvoice($lines);
        $details = [0 => $this->itemADetail(), 1 => $this->itemBDetail()];

        $document = $builder->build($invoice, $invoice->lines(), $details, $this->customer(), $this->businessProfile());

        foreach (['eInvoiceVersion', 'eInvoiceTypeCode', 'eInvoiceCodeNumber', 'eInvoiceDate', 'invoiceCurrencyCode', 'supplier', 'buyer', 'invoiceLineItems', 'legalMonetaryTotal'] as $field) {
            $this->assertArrayHasKey($field, $document, "Missing mandatory top-level field: {$field}");
        }

        foreach (['name', 'tin', 'registrationNumber', 'msicCode', 'address'] as $field) {
            $this->assertArrayHasKey($field, $document['supplier'], "Missing mandatory supplier field: {$field}");
        }

        foreach (['name', 'tin', 'address'] as $field) {
            $this->assertArrayHasKey($field, $document['buyer'], "Missing mandatory buyer field: {$field}");
        }

        foreach ($document['invoiceLineItems'] as $line) {
            foreach (['item', 'quantity', 'unitOfMeasure', 'unitPrice', 'taxType', 'taxRate', 'taxAmount', 'subtotal'] as $field) {
                $this->assertArrayHasKey($field, $line, "Missing mandatory line field: {$field}");
            }
        }

        foreach (['totalExcludingTax', 'totalTaxAmount', 'totalIncludingTax', 'totalPayableAmount'] as $field) {
            $this->assertArrayHasKey($field, $document['legalMonetaryTotal'], "Missing mandatory total field: {$field}");
        }
    }

    private function itemALine(): InvoiceLine
    {
        return InvoiceLine::of('Consulting services', 2, Money::fromDecimalString('500.00', Currency::of(self::CURRENCY)));
    }

    private function itemADetail(): MyInvoisLineDetail
    {
        return MyInvoisLineDetail::of(
            MyInvoisTaxType::ServiceTax,
            '6.00',
            Money::fromDecimalString('60.00', Currency::of(self::CURRENCY)),
            '9309000000',
            'DAY',
        );
    }

    private function itemBLine(): InvoiceLine
    {
        return InvoiceLine::of('Laptop stand', 1, Money::fromDecimalString('150.00', Currency::of(self::CURRENCY)));
    }

    private function itemBDetail(): MyInvoisLineDetail
    {
        return MyInvoisLineDetail::of(
            MyInvoisTaxType::NotApplicable,
            '0.00',
            Money::fromDecimalString('0.00', Currency::of(self::CURRENCY)),
            '4329900000',
            'EA',
        );
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

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private function issuedInvoice(array $lines): Invoice
    {
        return $this->draftInvoice($lines)->issue('INV-000001', new \DateTimeImmutable('2026-09-22'), JournalId::of('journal-0001'));
    }

    private function customer(array $overrides = []): Customer
    {
        $fields = array_merge([
            'name' => 'Aina Trading',
            'email' => 'aina@example.my',
            'phone' => '+60123456789',
            'address' => 'No. 5, Jalan Mawar, Shah Alam',
            'tin' => 'C1234567890',
        ], $overrides);

        return Customer::register(
            CustomerId::of('customer-0001'),
            TenantId::of('tenant-0001'),
            $fields['name'],
            $fields['email'],
            $fields['phone'],
            $fields['address'],
            $fields['tin'],
            null,
        );
    }

    private function businessProfile(array $overrides = []): BusinessProfile
    {
        $fields = array_merge([
            'legal_name' => 'Kedai Runcit Aina',
            'registration_number' => 'SSM-0012345',
            'tin' => 'IG12345678090',
            'sst_registration_number' => 'SST-0099887766',
            'msic_code' => '47111',
            'address_line1' => 'No. 12, Jalan Sutera',
            'address_line2' => null,
            'city' => 'Petaling Jaya',
            'state' => 'Selangor',
            'postcode' => '46000',
        ], $overrides);

        return new BusinessProfile($fields);
    }
}
