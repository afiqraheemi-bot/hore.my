<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Reporting;

use App\Domain\Accounting\Money\Money;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\InvoiceId;

/**
 * One outstanding Invoice's own row on the Debtors/Aging report (M22)
 * — only Issued Invoices with a non-zero outstanding balance ever
 * appear; a fully-paid or still-Draft Invoice contributes no line.
 */
final class AgingReportLine
{
    public function __construct(
        private readonly InvoiceId $invoiceId,
        private readonly ?string $invoiceNumber,
        private readonly CustomerId $customerId,
        private readonly \DateTimeImmutable $dueDate,
        private readonly Money $outstandingBalance,
        private readonly AgingBucket $bucket,
    ) {}

    public function invoiceId(): InvoiceId
    {
        return $this->invoiceId;
    }

    public function invoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function dueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function outstandingBalance(): Money
    {
        return $this->outstandingBalance;
    }

    public function bucket(): AgingBucket
    {
        return $this->bucket;
    }
}
