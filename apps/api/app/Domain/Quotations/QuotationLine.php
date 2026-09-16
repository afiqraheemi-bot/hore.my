<?php

declare(strict_types=1);

namespace App\Domain\Quotations;

use App\Domain\Accounting\Money\Money;
use App\Domain\Accounting\Money\RoundingMode;
use App\Domain\Invoicing\InvoiceLine;
use App\Domain\Quotations\Exception\InvalidQuotationLineException;

/**
 * One line item on a Quotation (AETS-016) — a Value Object, not an
 * entity: it carries no identity of its own beyond its position
 * within its owning Quotation's own `lines()` list. Deliberately
 * parallel to, but never reused from, {@see InvoiceLine} — a Quotation
 * is its own bounded context (AETS-016 §2.2: it has no ledger effect
 * at all), mirroring this codebase's own established module-boundary
 * discipline (e.g. ADR-0009's Workspace/Accounting split).
 *
 * **`quantity` is a positive integer, not a decimal**, for the
 * identical reason {@see InvoiceLine}'s own docblock explains:
 * {@see Money::multiply()} currently only accepts
 * {@see RoundingMode::Unnecessary}.
 */
final class QuotationLine
{
    private const MAX_DESCRIPTION_LENGTH = 500;

    private function __construct(
        private readonly string $description,
        private readonly int $quantity,
        private readonly Money $unitPrice,
        private readonly Money $lineAmount,
    ) {}

    /**
     * @throws InvalidQuotationLineException if `$description` is empty
     *                                       or too long, or `$quantity` is not positive.
     */
    public static function of(string $description, int $quantity, Money $unitPrice): self
    {
        if ($description === '') {
            throw InvalidQuotationLineException::forEmptyDescription();
        }

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw InvalidQuotationLineException::forDescriptionExceedingMaxLength(self::MAX_DESCRIPTION_LENGTH);
        }

        if ($quantity < 1) {
            throw InvalidQuotationLineException::forNonPositiveQuantity($quantity);
        }

        $lineAmount = $unitPrice->multiply((string) $quantity, RoundingMode::Unnecessary);

        return new self($description, $quantity, $unitPrice, $lineAmount);
    }

    /**
     * Reconstruct an already-validated line from persisted state — no
     * validation beyond each field's own bound, mirroring every other
     * `reconstitute()` in this codebase.
     */
    public static function reconstitute(string $description, int $quantity, Money $unitPrice, Money $lineAmount): self
    {
        return new self($description, $quantity, $unitPrice, $lineAmount);
    }

    public function description(): string
    {
        return $this->description;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function lineAmount(): Money
    {
        return $this->lineAmount;
    }

    /**
     * Whether this line's own persisted `lineAmount` still equals
     * `unitPrice × quantity` — mirrors
     * {@see InvoiceLine::hasConsistentLineAmount()}'s own 2026-09-11
     * audit-remediation reasoning, applied here from this Quotation's
     * own first version rather than added after the fact.
     */
    public function hasConsistentLineAmount(): bool
    {
        return $this->lineAmount->equals($this->unitPrice->multiply((string) $this->quantity, RoundingMode::Unnecessary));
    }
}
