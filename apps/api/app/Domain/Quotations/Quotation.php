<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

use App\Domain\Accounting\Money\Currency;
use App\Domain\Accounting\Money\MinorUnits;
use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Money\RoundingMode;
use App\Domain\Customers\CustomerId;
use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceId;
use App\Domain\Quotations\Exception\CorruptQuotationRecordException;
use App\Domain\Quotations\Exception\EmptyQuotationCannotBeSentException;
use App\Domain\Quotations\Exception\InvalidQuotationStatusTransitionException;
use App\Domain\Quotations\Exception\InvalidQuotationValidUntilException;
use App\Domain\Quotations\Exception\QuotationNotEditableException;
use App\Domain\Quotations\Exception\TooManyQuotationLinesException;
use App\Domain\Shared\Tenancy\TenantId;

/**
 * The Quotation aggregate root (AETS-016) — a Tenant's own pre-sale
 * document to a Customer, moving through the five states AETS-016 §4
 * defines: `Draft` (mutable, no ledger effect) -> `Sent` (locked,
 * numbered) -> `Accepted`/`Rejected`, and `Accepted` -> `Converted`
 * (AETS-016 §5: a new Draft Invoice is created from it).
 *
 * **Never produces a ledger effect of its own** (AETS-016 §2.2) — this
 * is the one structural fact separating this class from
 * {@see Invoice}, which this class otherwise
 * deliberately parallels in shape and conventions.
 *
 * **Immutable — every transition returns a new instance**, mirroring
 * {@see Invoice}'s own convention.
 */
final class Quotation
{
    private const MAX_LINE_COUNT = 200;

    /**
     * @param  list<QuotationLine>  $lines
     */
    private function __construct(
        private readonly QuotationId $id,
        private readonly TenantId $tenantId,
        private readonly CustomerId $customerId,
        private readonly ?string $quotationNumber,
        private readonly QuotationStatus $status,
        private readonly ?\DateTimeImmutable $issueDate,
        private readonly \DateTimeImmutable $validUntil,
        private readonly ?InvoiceId $convertedInvoiceId,
        private readonly array $lines,
        private readonly Money $totalAmount,
    ) {}

    /**
     * @param  list<QuotationLine>  $lines
     *
     * @throws TooManyQuotationLinesException if `$lines` exceeds the
     *                                        defensive count bound.
     */
    public static function draft(
        QuotationId $id,
        TenantId $tenantId,
        CustomerId $customerId,
        \DateTimeImmutable $validUntil,
        array $lines,
        Currency $currency,
    ): self {
        self::assertLineCountValid($lines);

        return new self($id, $tenantId, $customerId, null, QuotationStatus::Draft, null, $validUntil, null, $lines, self::sumLines($lines, $currency));
    }

    /**
     * Reconstruct an already-persisted Quotation from storage — see
     * {@see Invoice::reconstitute()}'s own
     * docblock for why this re-validates rather than trusting
     * persisted state unconditionally (QUO-005).
     *
     * @param  list<QuotationLine>  $lines
     *
     * @throws CorruptQuotationRecordException if any line's
     *                                         `lineAmount` does not equal `unitPrice × quantity`, or if
     *                                         `$totalAmount` does not equal the sum of `$lines`.
     */
    public static function reconstitute(
        QuotationId $id,
        TenantId $tenantId,
        CustomerId $customerId,
        ?string $quotationNumber,
        QuotationStatus $status,
        ?\DateTimeImmutable $issueDate,
        \DateTimeImmutable $validUntil,
        ?InvoiceId $convertedInvoiceId,
        array $lines,
        Money $totalAmount,
    ): self {
        foreach ($lines as $index => $line) {
            if (! $line->hasConsistentLineAmount()) {
                $expected = $line->unitPrice()->multiply((string) $line->quantity(), RoundingMode::Unnecessary);

                throw CorruptQuotationRecordException::forLineAmountMismatch($id, $index, $expected, $line->lineAmount());
            }
        }

        $expectedTotal = self::sumLines($lines, $totalAmount->currency());
        if (! $totalAmount->equals($expectedTotal)) {
            throw CorruptQuotationRecordException::forTotalAmountMismatch($id, $expectedTotal, $totalAmount);
        }

        return new self($id, $tenantId, $customerId, $quotationNumber, $status, $issueDate, $validUntil, $convertedInvoiceId, $lines, $totalAmount);
    }

    /**
     * @param  list<QuotationLine>  $lines
     *
     * @throws QuotationNotEditableException if this Quotation is no
     *                                       longer `Draft`.
     * @throws TooManyQuotationLinesException if `$lines` exceeds the
     *                                        defensive count bound.
     */
    public function update(CustomerId $customerId, \DateTimeImmutable $validUntil, array $lines): self
    {
        if ($this->status !== QuotationStatus::Draft) {
            throw QuotationNotEditableException::forNonDraftQuotation($this->id);
        }

        self::assertLineCountValid($lines);

        return new self($this->id, $this->tenantId, $customerId, null, QuotationStatus::Draft, null, $validUntil, null, $lines, self::sumLines($lines, $this->totalAmount->currency()));
    }

    /**
     * @throws InvalidQuotationStatusTransitionException if this
     *                                                   Quotation is not `Draft`.
     * @throws EmptyQuotationCannotBeSentException if this Quotation
     *                                             has no lines.
     * @throws InvalidQuotationValidUntilException if this Quotation's
     *                                             validity date is before `$issueDate`.
     */
    public function send(string $quotationNumber, \DateTimeImmutable $issueDate): self
    {
        if ($this->status !== QuotationStatus::Draft) {
            throw InvalidQuotationStatusTransitionException::forTransition($this->id, $this->status, 'send');
        }

        if ($this->lines === []) {
            throw EmptyQuotationCannotBeSentException::forQuotation($this->id);
        }

        if ($this->validUntil < $issueDate) {
            throw InvalidQuotationValidUntilException::forValidUntilBeforeIssueDate();
        }

        return new self($this->id, $this->tenantId, $this->customerId, $quotationNumber, QuotationStatus::Sent, $issueDate, $this->validUntil, null, $this->lines, $this->totalAmount);
    }

    /**
     * @throws InvalidQuotationStatusTransitionException unless the
     *                                                   current status is `Sent`.
     */
    public function accept(): self
    {
        if ($this->status !== QuotationStatus::Sent) {
            throw InvalidQuotationStatusTransitionException::forTransition($this->id, $this->status, 'accept');
        }

        return $this->with(QuotationStatus::Accepted);
    }

    /**
     * @throws InvalidQuotationStatusTransitionException unless the
     *                                                   current status is `Sent` or `Accepted`.
     */
    public function reject(): self
    {
        if (! in_array($this->status, [QuotationStatus::Sent, QuotationStatus::Accepted], true)) {
            throw InvalidQuotationStatusTransitionException::forTransition($this->id, $this->status, 'reject');
        }

        return $this->with(QuotationStatus::Rejected);
    }

    /**
     * @throws InvalidQuotationStatusTransitionException unless the
     *                                                   current status is `Accepted`.
     */
    public function convert(InvoiceId $convertedInvoiceId): self
    {
        if ($this->status !== QuotationStatus::Accepted) {
            throw InvalidQuotationStatusTransitionException::forTransition($this->id, $this->status, 'convert');
        }

        return new self($this->id, $this->tenantId, $this->customerId, $this->quotationNumber, QuotationStatus::Converted, $this->issueDate, $this->validUntil, $convertedInvoiceId, $this->lines, $this->totalAmount);
    }

    public function id(): QuotationId
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

    public function quotationNumber(): ?string
    {
        return $this->quotationNumber;
    }

    public function status(): QuotationStatus
    {
        return $this->status;
    }

    public function issueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function validUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function convertedInvoiceId(): ?InvoiceId
    {
        return $this->convertedInvoiceId;
    }

    /**
     * @return list<QuotationLine>
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
     * @param  list<QuotationLine>  $lines
     */
    private static function assertLineCountValid(array $lines): void
    {
        if (count($lines) > self::MAX_LINE_COUNT) {
            throw TooManyQuotationLinesException::forCount(count($lines), self::MAX_LINE_COUNT);
        }
    }

    /**
     * @param  list<QuotationLine>  $lines
     */
    private static function sumLines(array $lines, Currency $currency): Money
    {
        $total = Money::fromMinorUnits(MinorUnits::of('0'), $currency);

        foreach ($lines as $line) {
            $total = $total->add($line->lineAmount());
        }

        return $total;
    }

    private function with(QuotationStatus $status): self
    {
        return new self($this->id, $this->tenantId, $this->customerId, $this->quotationNumber, $status, $this->issueDate, $this->validUntil, $this->convertedInvoiceId, $this->lines, $this->totalAmount);
    }
}
