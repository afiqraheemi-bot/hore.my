<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Domain\Accounting\ChartOfAccounts\AccountId;
use App\Domain\Accounting\Journal\JournalId;
use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Banking\Reconciliation;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Exception\EmptyInvoiceCannotBeIssuedException;
use App\Domain\Invoicing\Exception\InvalidInvoiceDueDateException;
use App\Domain\Invoicing\Exception\InvalidInvoiceStatusTransitionException;
use App\Domain\Invoicing\Exception\InvoiceNotEditableException;
use App\Domain\Invoicing\Exception\TooManyInvoiceLinesException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Invoice aggregate root (M20, Modul 7 phase 2) — a Tenant's own
 * bill to a Customer, moving through exactly two states this milestone
 * models: `Draft` (mutable, no ledger effect) and `Issued` (immutable,
 * produces a Posted Journal via {@see InvoiceIssuingService}: Debit
 * Receivable Account / Credit Revenue Account).
 *
 * **Cancelling an Issued Invoice and Payment/Allocation are both out
 * of scope here** — see M20's Architecture Review. An Issued Invoice
 * is permanently locked once posted, mirroring a Posted Journal's own
 * append-only nature (AETS-004 §15); a correction mechanism (Journal
 * reversal-backed) is a tracked future milestone, not silently
 * omitted.
 *
 * **Immutable, every transition returns a new instance** — mirrors
 * {@see Reconciliation}'s own convention.
 */
final class Invoice
{
    private const MAX_LINE_COUNT = 200;

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private function __construct(
        private readonly InvoiceId $id,
        private readonly TenantId $tenantId,
        private readonly CustomerId $customerId,
        private readonly ?string $invoiceNumber,
        private readonly InvoiceStatus $status,
        private readonly ?\DateTimeImmutable $issueDate,
        private readonly \DateTimeImmutable $dueDate,
        private readonly AccountId $receivableAccountId,
        private readonly AccountId $revenueAccountId,
        private readonly ?JournalId $journalId,
        private readonly array $lines,
        private readonly Money $totalAmount,
    ) {}

    /**
     * @param  list<InvoiceLine>  $lines
     *
     * @throws TooManyInvoiceLinesException if `$lines` exceeds the
     *                                      defensive count bound.
     */
    public static function draft(
        InvoiceId $id,
        TenantId $tenantId,
        CustomerId $customerId,
        \DateTimeImmutable $dueDate,
        AccountId $receivableAccountId,
        AccountId $revenueAccountId,
        array $lines,
        Currency $currency,
    ): self {
        self::assertLineCountValid($lines);

        return new self(
            $id,
            $tenantId,
            $customerId,
            null,
            InvoiceStatus::Draft,
            null,
            $dueDate,
            $receivableAccountId,
            $revenueAccountId,
            null,
            $lines,
            self::sumLines($lines, $currency),
        );
    }

    /**
     * Reconstruct an already-persisted Invoice from storage — no
     * validation beyond each field's own bound, mirroring every other
     * `reconstitute()` in this codebase.
     *
     * @param  list<InvoiceLine>  $lines
     */
    public static function reconstitute(
        InvoiceId $id,
        TenantId $tenantId,
        CustomerId $customerId,
        ?string $invoiceNumber,
        InvoiceStatus $status,
        ?\DateTimeImmutable $issueDate,
        \DateTimeImmutable $dueDate,
        AccountId $receivableAccountId,
        AccountId $revenueAccountId,
        ?JournalId $journalId,
        array $lines,
        Money $totalAmount,
    ): self {
        return new self(
            $id,
            $tenantId,
            $customerId,
            $invoiceNumber,
            $status,
            $issueDate,
            $dueDate,
            $receivableAccountId,
            $revenueAccountId,
            $journalId,
            $lines,
            $totalAmount,
        );
    }

    /**
     * @param  list<InvoiceLine>  $lines
     *
     * @throws InvoiceNotEditableException if this Invoice is no longer
     *                                     `Draft`.
     * @throws TooManyInvoiceLinesException if `$lines` exceeds the
     *                                      defensive count bound.
     */
    public function update(
        \DateTimeImmutable $dueDate,
        AccountId $receivableAccountId,
        AccountId $revenueAccountId,
        array $lines,
    ): self {
        if ($this->status !== InvoiceStatus::Draft) {
            throw InvoiceNotEditableException::forIssuedInvoice($this->id);
        }

        self::assertLineCountValid($lines);

        return new self(
            $this->id,
            $this->tenantId,
            $this->customerId,
            null,
            InvoiceStatus::Draft,
            null,
            $dueDate,
            $receivableAccountId,
            $revenueAccountId,
            null,
            $lines,
            self::sumLines($lines, $this->totalAmount->currency()),
        );
    }

    /**
     * @throws InvalidInvoiceStatusTransitionException if this Invoice
     *                                                 is not `Draft`.
     * @throws EmptyInvoiceCannotBeIssuedException if this Invoice
     *                                             has no lines.
     * @throws InvalidInvoiceDueDateException if this
     *                                        Invoice's due date is before `$issueDate`.
     */
    public function issue(string $invoiceNumber, \DateTimeImmutable $issueDate, JournalId $journalId): self
    {
        if ($this->status !== InvoiceStatus::Draft) {
            throw InvalidInvoiceStatusTransitionException::forNonDraftInvoice($this->id, $this->status);
        }

        if ($this->lines === []) {
            throw EmptyInvoiceCannotBeIssuedException::forInvoice($this->id);
        }

        if ($this->dueDate < $issueDate) {
            throw InvalidInvoiceDueDateException::forDueDateBeforeIssueDate();
        }

        return new self(
            $this->id,
            $this->tenantId,
            $this->customerId,
            $invoiceNumber,
            InvoiceStatus::Issued,
            $issueDate,
            $this->dueDate,
            $this->receivableAccountId,
            $this->revenueAccountId,
            $journalId,
            $this->lines,
            $this->totalAmount,
        );
    }

    public function id(): InvoiceId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function invoiceNumber(): ?string
    {
        return $this->invoiceNumber;
    }

    public function status(): InvoiceStatus
    {
        return $this->status;
    }

    public function issueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function dueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function receivableAccountId(): AccountId
    {
        return $this->receivableAccountId;
    }

    public function revenueAccountId(): AccountId
    {
        return $this->revenueAccountId;
    }

    public function journalId(): ?JournalId
    {
        return $this->journalId;
    }

    /**
     * @return list<InvoiceLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalAmount(): Money
    {
        return $this->totalAmount;
    }

    public function equals(self $other): bool
    {
        return $this->id->equals($other->id);
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private static function assertLineCountValid(array $lines): void
    {
        if (count($lines) > self::MAX_LINE_COUNT) {
            throw TooManyInvoiceLinesException::forCount(count($lines), self::MAX_LINE_COUNT);
        }
    }

    /**
     * @param  list<InvoiceLine>  $lines
     */
    private static function sumLines(array $lines, Currency $currency): Money
    {
        $total = Money::fromMinorUnits(MinorUnits::of('0'), $currency);

        foreach ($lines as $line) {
            $total = $total->add($line->lineAmount());
        }

        return $total;
    }
}
