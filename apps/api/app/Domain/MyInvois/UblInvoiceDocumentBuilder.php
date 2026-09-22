<?php

declare(strict_types=1);

namespace App\Domain\MyInvois;

use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\Customer;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\MyInvois\Exception\MissingMyInvoisDataException;
use App\Models\BusinessProfile;

/**
 * Builds a MyInvois v1.0 (unsigned) Invoice document — UBL 2.1-shaped
 * JSON, per LHDN's published schema
 * (`sdk.myinvois.hasil.gov.my/documents/invoice-v1-1/`, reviewed
 * 2026-09-22) — from an Issued `Invoice` and its related data
 * (AETS-013 v0.1.0 §7).
 *
 * **Pure — no I/O, no database read.** Every input is supplied by the
 * caller; nothing here calls MyInvois or touches persistence. This is
 * deliberate: the shape of the document is the single highest-risk
 * detail in this integration (a subtly wrong tax total or a missing
 * mandatory field fails real submission, not a local test), so it is
 * built and proven here in isolation before anything ever depends on
 * it over the network (§2.2 — no live call exists yet in this
 * version).
 *
 * **Fails closed (MYI-003, MYI-004).** A `Draft` Invoice, a Business
 * Profile missing a mandatory Supplier field, a Customer missing its
 * TIN, or a line with no matching `MyInvoisLineDetail` all raise a
 * named `MissingMyInvoisDataException` — never a partial or
 * best-guess document.
 *
 * **Totals reconcile exactly (MYI-005).** `totalExcludingTax` and
 * `totalTaxAmount` are summed directly from each line's own
 * `InvoiceLine::lineAmount()`/`MyInvoisLineDetail::taxAmount()` — never
 * independently recomputed from unit price × quantity a second time,
 * which could silently diverge from the Invoice's own already-verified
 * total (`Invoice::reconstitute()` already guarantees `lineAmount`
 * consistency; this builder trusts that guarantee rather than
 * repeating it).
 */
final class UblInvoiceDocumentBuilder
{
    private const DOCUMENT_VERSION = '1.0';

    private const INVOICE_TYPE_CODE = '01';

    /**
     * @param  list<InvoiceLine>  $lines  Must be the same list `$invoice->lines()` returns, same order.
     * @param  array<int, MyInvoisLineDetail>  $lineDetails  Keyed by the same position as `$lines`.
     * @return array<string, mixed>
     *
     * @throws MissingMyInvoisDataException if `$invoice` is not Issued, or any mandatory field is missing.
     */
    public function build(
        Invoice $invoice,
        array $lines,
        array $lineDetails,
        Customer $customer,
        BusinessProfile $businessProfile,
    ): array {
        if ($invoice->status() !== InvoiceStatus::Issued) {
            throw MissingMyInvoisDataException::forNonIssuedInvoice($invoice->id());
        }

        $this->assertSupplierComplete($businessProfile);
        $this->assertBuyerComplete($customer);

        $currency = $invoice->totalAmount()->currency();
        $totalExcludingTax = Money::fromMinorUnits(MinorUnits::of('0'), $currency);
        $totalTax = Money::fromMinorUnits(MinorUnits::of('0'), $currency);
        $lineItems = [];

        foreach ($lines as $position => $line) {
            $detail = $lineDetails[$position] ?? null;
            if (! $detail instanceof MyInvoisLineDetail) {
                throw MissingMyInvoisDataException::forMissingLineDetail($invoice->id(), $position);
            }

            $totalExcludingTax = $totalExcludingTax->add($line->lineAmount());
            $totalTax = $totalTax->add($detail->taxAmount());

            $lineItems[] = [
                'id' => (string) ($position + 1),
                'item' => [
                    'description' => $line->description(),
                    'classificationCode' => $detail->classificationCode(),
                ],
                'quantity' => $line->quantity(),
                'unitOfMeasure' => $detail->unitOfMeasure(),
                'unitPrice' => $line->unitPrice()->toDecimalString(),
                'subtotal' => $line->lineAmount()->toDecimalString(),
                'taxType' => $detail->taxType()->value,
                'taxRate' => $detail->taxRate(),
                'taxAmount' => $detail->taxAmount()->toDecimalString(),
                'totalExcludingTax' => $line->lineAmount()->toDecimalString(),
            ];
        }

        $totalIncludingTax = $totalExcludingTax->add($totalTax);

        return [
            'eInvoiceVersion' => self::DOCUMENT_VERSION,
            'eInvoiceTypeCode' => self::INVOICE_TYPE_CODE,
            'eInvoiceCodeNumber' => $invoice->invoiceNumber(),
            'eInvoiceDate' => $invoice->issueDate()?->format('Y-m-d'),
            'eInvoiceTime' => $invoice->issueDate()?->format('H:i:s\Z'),
            'invoiceCurrencyCode' => $currency->identifier(),
            'supplier' => [
                'name' => $businessProfile->legal_name,
                'tin' => $businessProfile->tin,
                'registrationNumber' => $businessProfile->registration_number,
                'sstRegistrationNumber' => $businessProfile->sst_registration_number,
                'msicCode' => $businessProfile->msic_code,
                'address' => [
                    'addressLine1' => $businessProfile->address_line1,
                    'addressLine2' => $businessProfile->address_line2,
                    'city' => $businessProfile->city,
                    'state' => $businessProfile->state,
                    'postcode' => $businessProfile->postcode,
                    'countryCode' => 'MYS',
                ],
            ],
            'buyer' => [
                'name' => $customer->name(),
                'tin' => $customer->taxIdentificationNumber(),
                'address' => $customer->address(),
                'contactNumber' => $customer->phone(),
            ],
            'invoiceLineItems' => $lineItems,
            'legalMonetaryTotal' => [
                'totalExcludingTax' => $totalExcludingTax->toDecimalString(),
                'totalTaxAmount' => $totalTax->toDecimalString(),
                'totalIncludingTax' => $totalIncludingTax->toDecimalString(),
                'totalPayableAmount' => $totalIncludingTax->toDecimalString(),
            ],
        ];
    }

    private function assertSupplierComplete(BusinessProfile $businessProfile): void
    {
        if (($businessProfile->tin ?? '') === '') {
            throw MissingMyInvoisDataException::forMissingSupplierField('tin');
        }

        if (($businessProfile->msic_code ?? '') === '') {
            throw MissingMyInvoisDataException::forMissingSupplierField('msic_code');
        }
    }

    private function assertBuyerComplete(Customer $customer): void
    {
        if (($customer->taxIdentificationNumber() ?? '') === '') {
            throw MissingMyInvoisDataException::forMissingBuyerField('tin');
        }
    }
}
