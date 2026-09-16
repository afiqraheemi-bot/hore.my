<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Posting\Exception\RejectedAccountReferenceException;
use App\Domain\Invoicing\Exception\InvalidReceivableAccountTypeException;
use App\Domain\Invoicing\Exception\InvalidRevenueAccountTypeException;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceAccountTypeValidator;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Invoicing\InvoiceIssuingService;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Quotations\Exception\InvalidQuotationStatusTransitionException;
use App\Domain\Quotations\Exception\QuotationNotFoundException;
use App\Domain\Shared\Tenancy\TenantId;
use App\Infrastructure\Invoicing\InvoiceRepository;
use App\Infrastructure\Quotations\QuotationRepository;
use Illuminate\Database\ConnectionInterface;

/**
 * The application service that converts an `Accepted` Quotation into
 * a new, `Draft` Invoice (AETS-016 §5) — the one point where this
 * otherwise ledger-free module hands off to Invoicing. Never touches
 * Accounting Core: the resulting Invoice is a Draft, with zero ledger
 * effect, exactly as if a human had composed it by hand through the
 * ordinary Invoice creation endpoint. Issuing it afterward is that
 * same, unmodified flow.
 *
 * **Atomicity — the new Invoice and the Quotation's own `Converted`
 * transition commit or roll back together** (QUO-007): one outer
 * database transaction wraps both writes.
 *
 * **Concurrency — a pessimistic row lock on the Quotation is the very
 * first statement inside the transaction**, mirroring
 * {@see InvoiceIssuingService}'s own established
 * reasoning (its docblock, "Concurrency") exactly: two concurrent
 * conversion attempts against the same `Accepted` Quotation must never
 * both create an Invoice (QUO-008).
 */
final class QuotationConversionService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly QuotationRepository $quotationRepository,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly InvoiceAccountTypeValidator $accountTypeValidator,
    ) {}

    /**
     * @throws QuotationNotFoundException if no Quotation exists for
     *                                    `$quotationId` under this Tenant.
     * @throws InvalidQuotationStatusTransitionException if the
     *                                                   Quotation is not `Accepted`.
     * @throws RejectedAccountReferenceException if either Account
     *                                           cannot be resolved for this Tenant.
     * @throws InvalidReceivableAccountTypeException if the receivable
     *                                               Account's type is not `Asset`.
     * @throws InvalidRevenueAccountTypeException if the revenue
     *                                            Account's type is not `Revenue`.
     */
    public function convert(
        TenantId $tenantId,
        QuotationId $quotationId,
        InvoiceId $invoiceId,
        AccountId $receivableAccountId,
        AccountId $revenueAccountId,
        \DateTimeImmutable $dueDate,
    ): Invoice {
        return $this->connection->transaction(function () use ($tenantId, $quotationId, $invoiceId, $receivableAccountId, $revenueAccountId, $dueDate): Invoice {
            // Lock first, before any read informs a decision — see this
            // class's own docblock ("Concurrency").
            $this->connection->table('quotations')
                ->where('tenant_id', $tenantId->toString())
                ->where('id', $quotationId->toString())
                ->lockForUpdate()
                ->first();

            $quotation = $this->quotationRepository->findById($tenantId, $quotationId);

            if ($quotation === null) {
                throw QuotationNotFoundException::forId($quotationId);
            }

            $this->accountTypeValidator->validate($tenantId, $receivableAccountId, $revenueAccountId);

            $invoice = Invoice::draft(
                $invoiceId,
                $tenantId,
                $quotation->customerId(),
                $dueDate,
                $receivableAccountId,
                $revenueAccountId,
                array_map(
                    static fn (QuotationLine $line): InvoiceLine => InvoiceLine::of($line->description(), $line->quantity(), $line->unitPrice()),
                    $quotation->lines(),
                ),
                $quotation->totalAmount()->currency(),
            );

            $this->invoiceRepository->save($invoice);

            $converted = $quotation->convert($invoiceId);
            $this->quotationRepository->markConverted($converted);

            return $invoice;
        });
    }
}
